<?php

use App\Catalog\ProductImages;
use App\Checkout\DevelopmentCheckoutService;
use App\Digital\DigitalAssetEditor;
use App\Inventory\StockAvailability;
use App\Models\DevelopmentCheckout;
use App\Models\DigitalAsset;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\PaymentOutbox;
use App\Models\PaymentReceipt;
use App\Models\StockReservation;
use App\Models\User;
use App\Orders\OrderSummary;
use App\Payments\CapturedPayment;
use App\Payments\PaymentEventInbox;
use App\Payments\PaymentLedger;
use App\Payments\ProviderCheckoutSnapshot;
use App\Payments\ProviderIdentity;
use App\Payments\VerifiedProviderEvent;
use App\Storefront\GuestCart;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lunar\Models\Cart;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;

// Test-only entry point. This is not a production backup/restore command.
if (PHP_SAPI !== 'cli' || getenv('ONESHOP_RECOVERY_REHEARSAL') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('Use only the explicitly enabled isolated CI recovery rehearsal.');
}
$mode = $argv[1] ?? '';
if (! in_array($mode, ['seed', 'verify', 'wrong-key'], true)) {
    throw new RuntimeException('Expected seed, verify or wrong-key.');
}
umask(0077);
$root = dirname(__DIR__, 2);
$work = $root.'/build/recovery';
if (! is_dir($work)) {
    mkdir($work, 0700, true);
}
if ($mode !== 'seed') {
    $key = trim((string) file_get_contents($work.'/app_key'));
    if (! str_starts_with($key, 'base64:')) {
        throw new RuntimeException('Saved installation key is missing.');
    }
    if ($mode === 'wrong-key') {
        $key = 'base64:'.base64_encode(random_bytes(32));
    }
    putenv('APP_KEY='.$key);
    $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = $key;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('The rehearsal requires uncached, isolated configuration.');
}
if ($mode !== 'seed') {
    $app->useStoragePath($work.'/restored-storage');
}
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, 'Recovery failure: '.$exception->getMessage().PHP_EOL);
    exit(1);
});
$connection = DB::connection();
$expectedDatabase = $mode === 'seed' ? 'oneshop' : 'oneshop_restore';
$expectedHost = $connection->getDriverName() === 'pgsql' ? 'postgres' : 'mariadb';
if (! $app->environment('local', 'testing') || $connection->getConfig('host') !== $expectedHost || $connection->getDatabaseName() !== $expectedDatabase) {
    throw new RuntimeException('Refusing a database outside the isolated Compose rehearsal.');
}
if (! in_array($connection->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
    throw new RuntimeException('Unsupported rehearsal database.');
}

function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function fingerprint(): array
{
    $tables = [];
    foreach (DB::connection()->getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
        $rows = DB::table($table)->get()->map(fn ($row) => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
        sort($rows, SORT_STRING);
        $tables[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
    }
    ksort($tables);
    $files = [];
    foreach (['public', 'private'] as $disk) {
        foreach (Storage::disk($disk)->allFiles() as $path) {
            $files[$disk.'/'.$path] = hash('sha256', Storage::disk($disk)->get($path));
        }
    }
    ksort($files);

    return ['tables' => $tables, 'files' => $files];
}

function checkoutFixture(Session $session, int $quantity): DevelopmentCheckout
{
    $cup = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
    app(GuestCart::class)->add($session, $cup->id, $quantity);
    $checkout = app(DevelopmentCheckoutService::class);
    $checkout->address($session, ['first_name' => 'Recovery', 'last_name' => 'Demo', 'contact_email' => 'buyer@example.test', 'contact_phone' => '+385910000000',
        'line_one' => 'Testna ulica 1', 'city' => 'Zagreb', 'postcode' => '10000', 'country_id' => Country::query()->where('iso2', 'HR')->sole()->id]);
    $checkout->review($session);

    return $checkout->place($session, $session->get('oneshop.checkout_quote'));
}

function providerSnapshot(PaymentAttempt $attempt, string $status = 'open', bool $captured = false): ProviderCheckoutSnapshot
{
    $reference = str_replace('-', '', $attempt->id);

    return new ProviderCheckoutSnapshot(new ProviderIdentity('acct_recoveryTest'), 'cs_test_'.$reference, $attempt->id,
        $status, $attempt->amount_minor, 'EUR',
        $captured ? new CapturedPayment('ch_'.$reference, 'pi_'.$reference, $attempt->amount_minor, 'EUR') : null,
        $status === 'open' ? 'https://checkout.stripe.com/c/pay/recovery' : null);
}

function providerFixture(): PaymentAttempt
{
    $session = new Store('recovery-provider', new ArraySessionHandler(120));
    $session->start();
    $shirt = ProductVariant::query()->where('sku', 'DEMO-SHIRT-2-2')->sole();
    app(GuestCart::class)->add($session, $shirt->id, 1);
    app(DevelopmentCheckoutService::class)->address($session, [
        'first_name' => 'Provider', 'last_name' => 'Recovery', 'contact_email' => 'provider@example.test', 'contact_phone' => '+385910000000',
        'line_one' => 'Testna ulica 1', 'city' => 'Zagreb', 'postcode' => '10000', 'country_id' => Country::query()->where('iso2', 'HR')->sole()->id,
    ]);
    $cart = Cart::query()->findOrFail($session->get(GuestCart::SESSION_KEY));
    foreach ($cart->lines as $line) {
        $line->update(['meta' => ['oneshop_track_stock' => true]]);
    }
    $cart->refresh()->update(['meta' => ['oneshop_mode' => 'stripe-test']]);
    $order = $cart->createOrder();
    $order->update(['placed_at' => now()]);
    $cart->update(['completed_at' => now()]);
    $attempt = app(PaymentLedger::class)->prepare($order, new ProviderIdentity('acct_recoveryTest'), 'http://recovery.example.test');
    app(PaymentLedger::class)->bind($attempt, providerSnapshot($attempt));

    return $attempt->fresh();
}

if ($mode === 'seed') {
    ensure(! User::query()->exists() && ! ProductVariant::withTrashed()->exists() && ! Order::query()->exists(), 'Fixture creation requires a fresh empty installation.');
    ensure(Artisan::call('oneshop:demo-catalog') === 0 && Artisan::call('oneshop:demo-shipping') === 0, 'Demo initialization failed.');
    config(['media-library.queue_conversions_by_default' => false]);
    $operator = new User(['name' => 'Recovery fixture', 'email' => 'recovery@example.test', 'password' => Str::random(40)]);
    $operator->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
    $operator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    Auth::setUser($operator);

    $bag = ProductVariant::query()->where('sku', 'DEMO-BAG')->sole();
    $photo = imagecreatetruecolor(960, 640);
    imagefill($photo, 0, 0, imagecolorallocate($photo, 226, 233, 216));
    imagefilledrectangle($photo, 280, 180, 680, 540, imagecolorallocate($photo, 45, 74, 56));
    imagejpeg($photo, $work.'/photo.jpg', 90);
    unset($photo);
    app(ProductImages::class)->add($bag->product, new UploadedFile($work.'/photo.jpg', 'fixture.jpg', 'image/jpeg', UPLOAD_ERR_OK, true), ['title' => 'Recovery photograph', 'alt' => ['hr' => 'Testna fotografija']]);
    $bag->update(['shippable' => false]);
    file_put_contents($work.'/payload.txt', "OneShop recovery fixture\n");
    app(DigitalAssetEditor::class)->upload($bag, new UploadedFile($work.'/payload.txt', 'fixture.txt', 'text/plain', UPLOAD_ERR_OK, true), 'Recovery private asset');

    $session = app('session')->driver();
    $session->start();
    $cup = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
    $checkout = app(DevelopmentCheckoutService::class);
    $paid = checkoutFixture($session, 2);
    $checkout->simulate($session, $paid->id, 'paid');
    ensure((int) Order::query()->sole()->getRawOriginal('total') === 3500 && $cup->fresh()->stock === 18, 'Synthetic order/stock fixture failed.');
    checkoutFixture($session, 1);
    $cancelled = checkoutFixture($session, 1);
    $checkout->simulate($session, $cancelled->id, 'cancelled');
    $session->save();
    ensure(StockAvailability::held($cup->id) === 1 && StockAvailability::available($cup->fresh()) === 17
        && StockReservation::query()->count() === 3, 'Synthetic held/consumed/released reservations failed.');
    $ledger = app(PaymentLedger::class);
    $providerPaid = providerFixture();
    $ledger->reconcile($providerPaid, providerSnapshot($providerPaid, 'complete', true));
    $providerUnknown = providerFixture();
    $ledger->markUnknown($providerUnknown);
    $providerExpired = providerFixture();
    $ledger->reconcile($providerExpired, providerSnapshot($providerExpired, 'expired'));
    app(PaymentEventInbox::class)->record(new VerifiedProviderEvent(new ProviderIdentity('acct_recoveryTest'),
        'evt_recoveryDelivery', $providerPaid->provider_object_id, 'checkout.session.completed', hash('sha256', 'synthetic-recovery-event')));
    ensure(PaymentReceipt::query()->count() === 1 && PaymentOutbox::query()->count() === 1
        && PaymentEvent::query()->count() === 1, 'Synthetic payment history fixture failed.');
    file_put_contents($work.'/app_key', config('app.key'));
    file_put_contents($work.'/expected.json', json_encode(fingerprint(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo "Synthetic database, encrypted session/MFA, public photos and private asset prepared.\n";
    exit(0);
}

$operator = User::query()->where('email', 'recovery@example.test')->sole();
if ($mode === 'wrong-key') {
    $mfaRejected = false;
    $paymentRejected = false;
    try {
        $operator->getAppAuthenticationSecret();
    } catch (DecryptException) {
        $mfaRejected = true;
    }
    try {
        PaymentAttempt::query()->firstOrFail()->creation_parameters;
    } catch (DecryptException) {
        $paymentRejected = true;
    }
    ensure($mfaRejected && $paymentRejected, 'Encrypted identity/payment data unexpectedly accepted a different key.');
    $report = json_decode(file_get_contents($work.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $report['wrong_key_rejected'] = true;
    $report['payment_wrong_key_rejected'] = true;
    file_put_contents($work.'/report.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo "A different installation key was correctly rejected for identity and payment data.\n";
    exit(0);
}

$expected = json_decode(file_get_contents($work.'/expected.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = fingerprint();
ensure($actual === $expected, 'Restored database rows or public/private bytes differ from the snapshot.');
ensure($operator->getAppAuthenticationSecret() === 'JBSWY3DPEHPK3PXP', 'Encrypted MFA data did not survive with the saved key.');
Auth::setUser($operator);
$session = app('session')->driver();
$session->setId(DB::table('sessions')->sole()->id);
$session->start();
$owned = $session->get(DevelopmentCheckoutService::OWNED_KEY, []);
ensure(count($owned) === 3, 'Encrypted guest checkout session did not survive.');
$checkouts = DevelopmentCheckout::query()->whereIn('id', $owned)->get()->keyBy('status');
ensure($checkouts->count() === 3 && $checkouts->has(['paid', 'pending', 'cancelled']), 'Restored checkout states changed.');
foreach ($owned as $id) {
    app(DevelopmentCheckoutService::class)->owned($session, $id);
}
foreach ([StockReservation::HELD, StockReservation::CONSUMED, StockReservation::RELEASED] as $state) {
    ensure(StockReservation::query()->whereIn('reservation_key', $owned)->where('state', $state)->count() === 1, 'Restored reservation state changed.');
}
$unknownProvider = PaymentAttempt::query()->where('status', 'unknown')->sole();
ensure($unknownProvider->creation_parameters['line_items'][0]['price_data']['unit_amount'] === $unknownProvider->amount_minor
    && str_starts_with($unknownProvider->creation_parameters['success_url'], 'http://recovery.example.test/')
    && $unknownProvider->checkout_url === 'https://checkout.stripe.com/c/pay/recovery',
    'Restored payment request/hosted URL could not be decrypted.');
ensure(PaymentAttempt::query()->where('status', 'paid')->count() === 1 && PaymentAttempt::query()->where('status', 'expired')->count() === 1,
    'Restored provider payment states changed.');
$asset = DigitalAsset::query()->sole();
ob_start();
app(DigitalAssetEditor::class)->download($asset)->sendContent();
$download = ob_get_clean();
ensure(hash('sha256', $download) === $asset->sha256, 'Restored authorized private download failed.');
$order = $checkouts->get('paid')->order;
ensure($order->user_id === null && (int) $order->getRawOriginal('total') === 3500, 'Historical guest order changed.');
$pdf = app(OrderSummary::class)->pdf($order);
ensure(str_starts_with($pdf, '%PDF-'), 'Restored order summary did not render.');

$cup = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
ensure($cup->stock === 18 && StockAvailability::held($cup->id) === 1 && StockAvailability::available($cup) === 17,
    'Restored physical and available inventory do not match.');
$checkout = app(DevelopmentCheckoutService::class);
$pending = $checkouts->get('pending');
$transactionsBefore = Transaction::query()->count();
$checkout->simulate($session, $pending->id, 'paid');
$checkout->simulate($session, $pending->id, 'paid');
ensure($cup->fresh()->stock === 17 && StockAvailability::held($cup->id) === 0
    && Transaction::query()->count() === $transactionsBefore + 1, 'Restored pending hold did not settle exactly once.');
$previousReservation = StockReservation::query()->max('id');
$previousOrder = Order::query()->max('id');
$next = checkoutFixture($session, 1);
ensure(StockReservation::query()->where('reservation_key', $next->id)->sole()->id > $previousReservation
    && $next->order_id > $previousOrder, 'Restored reservation/order identity sequences did not advance.');
$checkout->simulate($session, $next->id, 'cancelled');
$checkout->simulate($session, $next->id, 'cancelled');
ensure($cup->fresh()->stock === 17 && StockAvailability::held($cup->id) === 0,
    'Restored inventory did not release a new hold exactly once.');
$shirt = ProductVariant::query()->where('sku', 'DEMO-SHIRT-2-2')->sole();
$stockBefore = $shirt->stock;
$receiptBefore = PaymentReceipt::query()->max('id');
$outboxBefore = PaymentOutbox::query()->count();
$transactionsBefore = Transaction::query()->count();
app(PaymentLedger::class)->reconcile($unknownProvider, providerSnapshot($unknownProvider, 'complete', true));
app(PaymentLedger::class)->reconcile($unknownProvider, providerSnapshot($unknownProvider, 'complete', true));
ensure($shirt->fresh()->stock === $stockBefore - 1 && StockAvailability::held($shirt->id) === 0
    && PaymentReceipt::query()->count() === 2 && PaymentOutbox::query()->count() === $outboxBefore + 1
    && Transaction::query()->count() === $transactionsBefore + 1, 'Restored provider capture was lost or duplicated.');
ensure(PaymentReceipt::query()->where('payment_attempt_id', $unknownProvider->id)->sole()->id > $receiptBefore,
    'Restored payment receipt identity did not advance.');
$event = PaymentEvent::query()->sole();
ensure(app(PaymentEventInbox::class)->record(new VerifiedProviderEvent(new ProviderIdentity('acct_recoveryTest'),
    $event->provider_event_id, $event->provider_object_id, $event->event_type, $event->body_sha256))->id === $event->id
    && PaymentEvent::query()->count() === 1, 'Restored webhook delivery deduplication failed.');
try {
    DB::transaction(function () use ($cup): void {
        $cup->replicate(['oneshop_normalized_sku'])->save();
        throw new RuntimeException('Restored SKU uniqueness constraint is missing.');
    });
} catch (UniqueConstraintViolationException) {
    // The restored database retained its unique index.
}
$previousId = User::query()->max('id');
$probe = User::query()->create(['name' => 'Sequence probe', 'email' => 'sequence@example.test', 'password' => Str::random(40)]);
ensure($probe->id > $previousId, 'Restored identity/sequence did not advance.');
$probe->delete();
$report = ['database' => $connection->getDriverName(), 'revision' => getenv('GITHUB_SHA') ?: 'unrecorded',
    'tables_verified' => count($actual['tables']), 'rows_verified' => array_sum(array_column($actual['tables'], 'rows')),
    'files_verified' => count($actual['files']), 'snapshot_sha256' => hash('sha256', json_encode($actual, JSON_THROW_ON_ERROR)),
    'saved_key_decrypts_mfa_and_session' => true, 'authorized_private_download' => true,
    'historical_order_pdf' => true, 'sku_unique_index' => true, 'identity_sequence' => true, 'wrong_key_rejected' => false];
$report += ['reservation_states_restored' => true, 'restored_hold_settled_once' => true,
    'new_hold_released_once' => true, 'reservation_order_sequences' => true];
$report += ['payment_request_decrypts' => true, 'payment_receipt_outbox_replay' => true,
    'payment_event_deduplication' => true, 'payment_identity_sequences' => true, 'payment_wrong_key_rejected' => false];
file_put_contents($work.'/report.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Restored rows, files, encrypted data, internal PDF, index and sequence verified.\n";
