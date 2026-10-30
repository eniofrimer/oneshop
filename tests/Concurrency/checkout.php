<?php

declare(strict_types=1);

use App\Checkout\DevelopmentCheckoutService;
use App\Models\DevelopmentCheckout;
use App\Storefront\GuestCart;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Cart;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
});
$connection = DB::connection();
$driver = $connection->getDriverName();
$socket = (string) $connection->getConfig('unix_socket');
$compose = in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)
    && $connection->getConfig('host') === ($driver === 'pgsql' ? 'postgres' : 'mariadb') && $socket === '';
$isolatedSocket = in_array($driver, ['mysql', 'mariadb'], true)
    && preg_match('#^/tmp/oneshop-tests-\d{8}_\d{6}/database/mysql\.sock$#D', $socket) === 1;
if (getenv('ONESHOP_CHECKOUT_CONCURRENCY') !== '1' || ! $app->environment('testing')
    || $app->configurationIsCached() || $connection->getDatabaseName() !== 'oneshop_test' || (! $compose && ! $isolatedSocket)) {
    throw new RuntimeException('Use an explicitly acknowledged, disposable oneshop_test installation.');
}
if ($isolatedSocket) {
    $server = $connection->selectOne('select @@datadir as directory, @@skip_networking as no_network');
    if (rtrim($server->directory, '/') !== dirname($socket) || (int) $server->no_network !== 1) {
        throw new RuntimeException('The isolated socket must belong to its own non-networked test daemon.');
    }
}
config(['oneshop.demo_checkout' => true, 'queue.default' => 'sync', 'cache.default' => 'array',
    'lunar.pricing.stored_inclusive_of_tax' => false]);
app()->setLocale('hr');

function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function sessionFor(array $attributes = []): Store
{
    $session = new Store('concurrency-test', new ArraySessionHandler(120));
    $session->start();
    $session->replace($attributes);

    return $session;
}

// Each child boots Laravel and opens its own physical database connection.
if (($argv[1] ?? '') === 'worker') {
    $file = $argv[2] ?? '';
    ensure(str_starts_with($file, storage_path('framework/testing/checkout-concurrency/')), 'Unexpected worker path.');
    $job = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $signalled = false;
    DB::connection()->beforeExecuting(function (string $sql) use ($job, $file, &$signalled): void {
        if (! $signalled && str_contains(strtolower($sql), 'for update')
            && (str_contains($sql, '"'.$job['barrier_table'].'"') || str_contains($sql, chr(96).$job['barrier_table'].chr(96)))) {
            file_put_contents($file.'.ready', 'ready', LOCK_EX);
            $signalled = true;
        }
    });
    $session = sessionFor($job['session']);
    try {
        $checkout = $job['operation'] === 'place'
            ? app(DevelopmentCheckoutService::class)->place($session, $job['quote'])
            : app(DevelopmentCheckoutService::class)->simulate($session, $job['checkout'], 'paid');
        $result = ['result' => 'ok', 'id' => $checkout->id, 'status' => $checkout->status];
    } catch (ValidationException $exception) {
        $result = ['result' => 'validation', 'fields' => array_keys($exception->errors())];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}

ensure(! Product::query()->exists() && ! Cart::query()->exists() && ! Order::query()->exists(),
    'Concurrency fixtures require an empty disposable catalog and checkout database.');
ensure(Artisan::call('oneshop:demo-catalog') === 0, Artisan::output());
ensure(Artisan::call('oneshop:demo-shipping') === 0, Artisan::output());
umask(0077);
$directory = storage_path('framework/testing/checkout-concurrency/'.bin2hex(random_bytes(12)));
mkdir($directory, 0700, recursive: true);
$processes = [];

function prepared(ProductVariant $variant): array
{
    $session = sessionFor();
    app(GuestCart::class)->add($session, $variant->id, 1);
    app(DevelopmentCheckoutService::class)->address($session, [
        'first_name' => 'Concurrent', 'last_name' => 'Fixture', 'contact_email' => 'race@example.test',
        'contact_phone' => '+385 91 000 0000', 'line_one' => 'Testna ulica 1', 'line_two' => '',
        'city' => 'Zagreb', 'postcode' => '10000', 'country_id' => Country::query()->where('iso2', 'HR')->sole()->id,
    ]);
    app(DevelopmentCheckoutService::class)->review($session);

    return ['operation' => 'place', 'session' => $session->all(), 'quote' => $session->get('oneshop.checkout_quote')];
}

function placed(ProductVariant $variant): array
{
    $job = prepared($variant);
    $session = sessionFor($job['session']);
    $checkout = app(DevelopmentCheckoutService::class)->place($session, $job['quote']);

    return ['operation' => 'simulate', 'session' => $session->all(), 'checkout' => $checkout->id];
}

function compete(array $jobs, string $table, Closure $hold, ?Closure $change = null): array
{
    global $directory, $processes;
    $batch = [];
    DB::beginTransaction();
    try {
        $hold();
        foreach ($jobs as $index => $job) {
            $file = $directory.'/'.bin2hex(random_bytes(8)).'-'.$index.'.json';
            file_put_contents($file, json_encode([...$job, 'barrier_table' => $table], JSON_THROW_ON_ERROR), LOCK_EX);
            $process = new Process([PHP_BINARY, __FILE__, 'worker', $file], base_path());
            $process->setTimeout(30)->start();
            $processes[] = $process;
            $batch[] = [$file, $process];
        }
        $deadline = microtime(true) + 15;
        foreach ($batch as [$file, $process]) {
            while (! is_file($file.'.ready')) {
                ensure($process->isRunning(), 'Worker failed before reaching lock: '.$process->getErrorOutput());
                ensure(microtime(true) < $deadline, 'Worker never reached the selected database lock.');
                usleep(10000);
            }
        }
        // Workers have reached the contested query while the parent owns its row.
        usleep(100000);
        foreach ($batch as [$file, $process]) {
            ensure($process->isRunning() && $process->getOutput() === '', 'Worker bypassed the contested database lock.');
        }
        $change?->__invoke();
        DB::commit();
    } catch (Throwable $exception) {
        DB::rollBack();
        throw $exception;
    }
    $results = [];
    foreach ($batch as [$file, $process]) {
        $process->wait();
        ensure($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    return $results;
}

try {
    $isolation = (string) DB::selectOne($driver === 'pgsql'
        ? "SELECT current_setting('transaction_isolation') AS isolation_level"
        : 'SELECT @@session.tx_isolation AS isolation_level')->isolation_level;
    ensure(strtoupper(str_replace('-', ' ', $isolation)) === 'READ COMMITTED', 'Unexpected application transaction isolation.');
    $report = ['database' => $driver, 'isolation' => $isolation, 'scenarios' => []];
    $cup = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
    $job = prepared($cup);
    $ordersBefore = Order::query()->count();
    $results = compete([$job, $job], (new Cart)->getTable(),
        fn () => Cart::query()->lockForUpdate()->findOrFail(data_get($job['session'], GuestCart::SESSION_KEY)));
    ensure($results[0]['result'] === 'ok' && $results[1]['result'] === 'ok'
        && $results[0]['id'] === $results[1]['id'] && Order::query()->count() === $ordersBefore + 1,
        'Simultaneous placement did not return one order.');
    $report['scenarios']['duplicate_placement'] = $results;

    $checkoutId = $results[0]['id'];
    $paid = ['operation' => 'simulate', 'checkout' => $checkoutId,
        'session' => [DevelopmentCheckoutService::OWNED_KEY => [$checkoutId]]];
    $stockBefore = $cup->fresh()->stock;
    $results = compete([$paid, $paid], (new DevelopmentCheckout)->getTable(),
        fn () => DevelopmentCheckout::query()->lockForUpdate()->findOrFail($checkoutId));
    ensure(array_column($results, 'status') === ['paid', 'paid']
        && $cup->fresh()->stock === $stockBefore - 1 && Transaction::query()->count() === 1,
        'Duplicate settlement consumed stock or captured twice.');
    $report['scenarios']['duplicate_settlement'] = $results;

    $cup->update(['stock' => 1]);
    $jobs = [placed($cup), placed($cup)];
    $transactionsBefore = Transaction::query()->count();
    $results = compete($jobs, $cup->getTable(),
        fn () => ProductVariant::query()->lockForUpdate()->findOrFail($cup->id));
    $outcomes = array_column($results, 'result');
    sort($outcomes);
    ensure($outcomes === ['ok', 'validation'] && $cup->fresh()->stock === 0
        && Transaction::query()->count() === $transactionsBefore + 1,
        'Competing orders did not protect the last unit.');
    $rejected = array_values(array_filter($results, fn (array $result): bool => $result['result'] === 'validation'))[0];
    ensure($rejected['fields'] === ['payment'], 'Unexpected last-unit failure.');
    $report['scenarios']['last_unit'] = $results;

    $shirt = ProductVariant::query()->where('sku', 'DEMO-SHIRT-2-2')->sole();
    $job = prepared($shirt);
    $ordersBefore = Order::query()->count();
    $results = compete([$job], (new Product)->getTable(),
        fn () => Product::query()->lockForUpdate()->findOrFail($shirt->product_id),
        fn () => Price::query()->whereMorphedTo('priceable', $shirt)->update(['price' => 9999]));
    ensure($results[0]['result'] === 'validation' && $results[0]['fields'] === ['quote']
        && Order::query()->count() === $ordersBefore,
        'Checkout accepted stale prices after waiting for a catalog edit: '.json_encode($results));
    $report['scenarios']['price_change_while_waiting'] = $results;

    $job = prepared($shirt);
    $value = $shirt->values()->firstOrFail();
    $results = compete([$job], (new ProductOptionValue)->getTable(),
        fn () => ProductOptionValue::query()->lockForUpdate()->findOrFail($value->id),
        fn () => $value->update(['name' => ['hr' => 'Promijenjena opcija']]));
    ensure($results[0]['result'] === 'validation' && $results[0]['fields'] === ['quote']
        && Order::query()->count() === $ordersBefore, 'Checkout accepted a changed option after its lock wait.');
    $report['scenarios']['option_change_while_waiting'] = $results;
    $report['result'] = 'passed';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop(1);
        }
    }
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
