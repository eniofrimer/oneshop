<?php

declare(strict_types=1);

use App\Checkout\DevelopmentCheckoutService;
use App\Checkout\StripeSandboxCheckout;
use App\Inventory\StockAvailability;
use App\Models\DevelopmentCheckout;
use App\Models\PaymentAttempt;
use App\Models\PaymentOutbox;
use App\Models\PaymentReceipt;
use App\Models\StockReservation;
use App\Payments\CapturedPayment;
use App\Payments\PaymentLedger;
use App\Payments\ProviderCheckoutSnapshot;
use App\Payments\ProviderIdentity;
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
use Tests\Support\StripeTestCredentials;

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
    'lunar.pricing.stored_inclusive_of_tax' => false,
    'payments.stripe_sandbox.enabled' => true, 'payments.stripe_sandbox.account' => 'acct_concurrencyTest',
    'payments.stripe_sandbox.secret_key' => StripeTestCredentials::secretKey(),
    'payments.stripe_sandbox.webhook_secret' => StripeTestCredentials::webhookSecret()]);
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
        $expired = null;
        if ($job['operation'] === 'stripe-place') {
            $checkout = app(StripeSandboxCheckout::class)->place($session, $job['quote']);
        } elseif ($job['operation'] === 'provider-reconcile') {
            $attempt = PaymentAttempt::query()->findOrFail($job['attempt']);
            $snapshot = new ProviderCheckoutSnapshot(new ProviderIdentity('acct_concurrencyTest'), $attempt->provider_object_id,
                $attempt->id, $job['provider_status'], $attempt->amount_minor, 'EUR',
                $job['capture'] ? new CapturedPayment('ch_'.str_replace('-', '', $attempt->idempotency_key), 'pi_'.str_replace('-', '', $attempt->idempotency_key), $attempt->amount_minor, 'EUR') : null);
            $checkout = app(PaymentLedger::class)->reconcile($attempt, $snapshot);
        } elseif ($job['operation'] === 'expire') {
            $expired = app(DevelopmentCheckoutService::class)->expirePending();
            $checkout = DevelopmentCheckout::query()->findOrFail($job['checkout']);
        } else {
            $checkout = $job['operation'] === 'place'
                ? app(DevelopmentCheckoutService::class)->place($session, $job['quote'])
                : app(DevelopmentCheckoutService::class)->simulate($session, $job['checkout'], $job['outcome'] ?? 'paid');
        }
        $result = ['result' => 'ok', 'id' => $checkout->id, 'status' => $checkout->status];
        if ($expired !== null) {
            $result['expired_count'] = $expired;
        }
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

function prepared(ProductVariant $variant, ?ProductVariant $other = null): array
{
    $session = sessionFor();
    app(GuestCart::class)->add($session, $variant->id, 1);
    if ($other) {
        app(GuestCart::class)->add($session, $other->id, 1);
    }
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

function placedLegacy(ProductVariant $variant): array
{
    $job = placed($variant);
    // Disposable pre-upgrade fixtures have no reservation rows.
    StockReservation::query()->where('reservation_key', $job['checkout'])->delete();
    DB::table('development_checkouts')->where('id', $job['checkout'])->update(['uses_stock_reservations' => false]);
    foreach (DevelopmentCheckout::query()->findOrFail($job['checkout'])->order->productLines()->get() as $line) {
        $line->update(['meta' => ['oneshop_demo_track_stock' => true]]);
    }

    return $job;
}

function providerAttempt(ProductVariant $variant): PaymentAttempt
{
    $job = prepared($variant);
    $cart = Cart::query()->findOrFail(data_get($job['session'], GuestCart::SESSION_KEY));
    foreach ($cart->lines as $line) {
        $line->update(['meta' => ['oneshop_track_stock' => true]]);
    }
    $cart->refresh()->update(['meta' => ['oneshop_mode' => 'stripe-test']]);
    $order = $cart->createOrder();
    $order->update(['placed_at' => now()]);
    $cart->update(['completed_at' => now()]);
    $attempt = app(PaymentLedger::class)->prepare($order, new ProviderIdentity('acct_concurrencyTest'), 'http://shop.example.test');
    app(PaymentLedger::class)->bind($attempt, new ProviderCheckoutSnapshot(new ProviderIdentity('acct_concurrencyTest'),
        'cs_test_'.str_replace('-', '', $attempt->id), $attempt->id, 'open', $attempt->amount_minor, 'EUR'));

    return $attempt->fresh();
}

/** Synthetic terminal provider evidence; no network calls in this rehearsal. */
function releasePlacedFixture(string $id): void
{
    $attempt = PaymentAttempt::query()->find($id);
    if ($attempt) {
        $snapshot = new ProviderCheckoutSnapshot(new ProviderIdentity('acct_concurrencyTest'),
            'cs_test_'.str_replace('-', '', $attempt->id), $attempt->id, 'expired', $attempt->amount_minor, 'EUR');
        app(PaymentLedger::class)->bind($attempt, $snapshot);
        app(PaymentLedger::class)->reconcile($attempt, $snapshot);
    } else {
        app(DevelopmentCheckoutService::class)->simulate(sessionFor([DevelopmentCheckoutService::OWNED_KEY => [$id]]), $id, 'cancelled');
    }
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

    $cup->refresh()->update(['stock' => 1]);
    $jobs = [prepared($cup), prepared($cup)];
    $transactionsBefore = Transaction::query()->count();
    $ordersBefore = Order::query()->count();
    $results = compete($jobs, (new Product)->getTable(),
        fn () => Product::query()->lockForUpdate()->findOrFail($cup->product_id));
    $outcomes = array_column($results, 'result');
    sort($outcomes);
    ensure($outcomes === ['ok', 'validation'] && $cup->fresh()->stock === 1
        && StockAvailability::held($cup->id) === 1 && Order::query()->count() === $ordersBefore + 1
        && Transaction::query()->count() === $transactionsBefore,
        'Competing orders did not reserve the last unit once.');
    $rejected = array_values(array_filter($results, fn (array $result): bool => $result['result'] === 'validation'))[0];
    ensure($rejected['fields'] === ['cart'], 'Unexpected last-unit reservation failure.');
    $report['scenarios']['last_unit_reservation'] = $results;
    $winner = array_values(array_filter($results, fn (array $result): bool => $result['result'] === 'ok'))[0]['id'];
    app(DevelopmentCheckoutService::class)->simulate(sessionFor([DevelopmentCheckoutService::OWNED_KEY => [$winner]]), $winner, 'paid');
    ensure($cup->fresh()->stock === 0 && StockAvailability::held($cup->id) === 0
        && Transaction::query()->count() === $transactionsBefore + 1, 'Reserved last unit did not settle once.');

    $bag = ProductVariant::query()->where('sku', 'DEMO-BAG')->sole();
    $bag->update(['stock' => 3]);
    $job = placed($bag);
    $transactionsBefore = Transaction::query()->count();
    $results = compete([$job, [...$job, 'outcome' => 'cancelled']], (new DevelopmentCheckout)->getTable(),
        fn () => DevelopmentCheckout::query()->lockForUpdate()->findOrFail($job['checkout']));
    $status = DevelopmentCheckout::query()->findOrFail($job['checkout'])->status;
    ensure(in_array($status, ['paid', 'cancelled'], true) && array_column($results, 'status') === [$status, $status]
        && StockAvailability::held($bag->id) === 0
        && $bag->fresh()->stock === ($status === 'paid' ? 2 : 3)
        && Transaction::query()->count() === $transactionsBefore + ($status === 'paid' ? 1 : 0),
        'Cancellation/settlement race violated the reservation or payment state.');
    $report['scenarios']['cancel_or_settle'] = $results;

    $cup->refresh()->update(['stock' => 1]);
    $bag->refresh()->update(['stock' => 1]);
    $jobs = [prepared($cup, $bag), prepared($bag, $cup)];
    $ordersBefore = Order::query()->count();
    $results = compete($jobs, (new Product)->getTable(),
        fn () => Product::query()->whereIn('id', [$cup->product_id, $bag->product_id])->orderBy('id')->lockForUpdate()->get());
    $outcomes = array_column($results, 'result');
    sort($outcomes);
    ensure($outcomes === ['ok', 'validation'] && StockAvailability::held($cup->id) === 1
        && StockAvailability::held($bag->id) === 1 && $cup->fresh()->stock === 1 && $bag->fresh()->stock === 1
        && Order::query()->count() === $ordersBefore + 1, 'Opposite cart order did not allocate both SKU holds atomically.');
    $winner = array_values(array_filter($results, fn (array $result): bool => $result['result'] === 'ok'))[0]['id'];
    app(DevelopmentCheckoutService::class)->simulate(sessionFor([DevelopmentCheckoutService::OWNED_KEY => [$winner]]), $winner, 'cancelled');
    ensure(StockAvailability::held($cup->id) === 0 && StockAvailability::held($bag->id) === 0,
        'Multi-SKU cancellation did not release both holds.');
    $report['scenarios']['opposite_sku_order'] = $results;

    $job = placed($bag);
    DB::table('development_checkouts')->where('id', $job['checkout'])->update(['expires_at' => now()->subMinute()]);
    $transactionsBefore = Transaction::query()->count();
    $results = compete([$job, [...$job, 'operation' => 'expire']], (new DevelopmentCheckout)->getTable(),
        fn () => DevelopmentCheckout::query()->lockForUpdate()->findOrFail($job['checkout']));
    ensure(array_column($results, 'status') === ['expired', 'expired'] && $bag->fresh()->stock === 1
        && StockAvailability::held($bag->id) === 0 && Transaction::query()->count() === $transactionsBefore,
        'Expiry/settlement race consumed an expired simulator hold.');
    $report['scenarios']['expire_or_settle'] = $results;

    $jobs = [placedLegacy($cup), placedLegacy($cup)];
    $transactionsBefore = Transaction::query()->count();
    $results = compete($jobs, $cup->getTable(),
        fn () => ProductVariant::query()->lockForUpdate()->findOrFail($cup->id));
    $outcomes = array_column($results, 'result');
    sort($outcomes);
    ensure($outcomes === ['ok', 'validation'] && $cup->fresh()->stock === 0 && StockAvailability::held($cup->id) === 0
        && Transaction::query()->count() === $transactionsBefore + 1, 'Legacy orders sold the last unit twice.');
    $report['scenarios']['legacy_last_unit_settlement'] = $results;

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

    $bag->refresh()->update(['stock' => 3]);
    $attempt = providerAttempt($bag);
    $job = ['operation' => 'provider-reconcile', 'session' => [], 'attempt' => $attempt->id,
        'provider_status' => 'complete', 'capture' => true];
    $transactionsBefore = Transaction::query()->count();
    $receiptsBefore = PaymentReceipt::query()->count();
    $outboxBefore = PaymentOutbox::query()->count();
    $results = compete([$job, $job], (new Order)->getTable(),
        fn () => Order::query()->lockForUpdate()->findOrFail($attempt->order_id));
    ensure(array_column($results, 'status') === ['paid', 'paid'] && $bag->fresh()->stock === 2
        && StockAvailability::held($bag->id) === 0 && Transaction::query()->count() === $transactionsBefore + 1
        && PaymentReceipt::query()->count() === $receiptsBefore + 1 && PaymentOutbox::query()->count() === $outboxBefore + 1,
        'Concurrent provider confirmations duplicated the receipt, inventory, transaction or outbox.');
    $report['scenarios']['provider_duplicate_capture'] = $results;

    $attempt = providerAttempt($bag);
    $job['attempt'] = $attempt->id;
    $transactionsBefore = Transaction::query()->count();
    $receiptsBefore = PaymentReceipt::query()->count();
    $outboxBefore = PaymentOutbox::query()->count();
    $results = compete([$job, [...$job, 'provider_status' => 'expired', 'capture' => false]], (new Order)->getTable(),
        fn () => Order::query()->lockForUpdate()->findOrFail($attempt->order_id));
    $status = $attempt->fresh()->status;
    ensure(in_array($status, ['paid', 'review'], true) && StockAvailability::held($bag->id) === 0
        && $bag->fresh()->stock === ($status === 'paid' ? 1 : 2)
        && Transaction::query()->count() === $transactionsBefore + ($status === 'paid' ? 1 : 0)
        && PaymentReceipt::query()->count() === $receiptsBefore + 1
        && PaymentOutbox::query()->count() === $outboxBefore + ($status === 'paid' ? 1 : 2),
        'Expiry/capture race lost a confirmed receipt or incorrectly allocated released inventory.');
    $report['scenarios']['provider_expiry_or_capture'] = ['final_status' => $status, 'workers' => $results];

    $cup->refresh()->update(['stock' => 3]);
    $job = [...prepared($cup), 'operation' => 'stripe-place'];
    $ordersBefore = Order::query()->count();
    $attemptsBefore = PaymentAttempt::query()->count();
    $results = compete([$job, $job], (new Cart)->getTable(),
        fn () => Cart::query()->lockForUpdate()->findOrFail(data_get($job['session'], GuestCart::SESSION_KEY)));
    ensure(array_column($results, 'result') === ['ok', 'ok'] && $results[0]['id'] === $results[1]['id']
        && Order::query()->count() === $ordersBefore + 1 && PaymentAttempt::query()->count() === $attemptsBefore + 1
        && StockAvailability::held($cup->id) === 1 && $cup->fresh()->stock === 3,
        'Concurrent Stripe submissions created multiple orders, attempts or holds.');
    releasePlacedFixture($results[0]['id']);
    $report['scenarios']['stripe_duplicate_placement'] = $results;

    $job = prepared($cup);
    $ordersBefore = Order::query()->count();
    $attemptsBefore = PaymentAttempt::query()->count();
    $simulatorsBefore = DevelopmentCheckout::query()->count();
    $results = compete([$job, [...$job, 'operation' => 'stripe-place']], (new Cart)->getTable(),
        fn () => Cart::query()->lockForUpdate()->findOrFail(data_get($job['session'], GuestCart::SESSION_KEY)));
    $outcomes = array_column($results, 'result');
    sort($outcomes);
    ensure($outcomes === ['ok', 'validation'] && Order::query()->count() === $ordersBefore + 1
        && PaymentAttempt::query()->count() + DevelopmentCheckout::query()->count() === $attemptsBefore + $simulatorsBefore + 1
        && StockAvailability::held($cup->id) === 1 && $cup->fresh()->stock === 3,
        'Concurrent payment modes both placed the same cart.');
    $winner = array_values(array_filter($results, fn (array $result): bool => $result['result'] === 'ok'))[0]['id'];
    releasePlacedFixture($winner);
    $report['scenarios']['stripe_or_simulator_same_cart'] = $results;

    $cup->refresh()->update(['stock' => 1]);
    $jobs = [prepared($cup), [...prepared($cup), 'operation' => 'stripe-place']];
    $ordersBefore = Order::query()->count();
    $results = compete($jobs, (new Product)->getTable(),
        fn () => Product::query()->lockForUpdate()->findOrFail($cup->product_id));
    $outcomes = array_column($results, 'result');
    sort($outcomes);
    ensure($outcomes === ['ok', 'validation'] && Order::query()->count() === $ordersBefore + 1
        && StockAvailability::held($cup->id) === 1 && $cup->fresh()->stock === 1,
        'Different payment modes over-reserved the last unit.');
    $winner = array_values(array_filter($results, fn (array $result): bool => $result['result'] === 'ok'))[0]['id'];
    releasePlacedFixture($winner);
    $report['scenarios']['stripe_or_simulator_last_unit'] = $results;
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
