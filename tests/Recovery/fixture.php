<?php

use App\Catalog\ProductImages;
use App\Checkout\DevelopmentCheckoutService;
use App\Digital\DigitalAssetEditor;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Orders\OrderSummary;
use App\Storefront\GuestCart;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\ProductVariant;

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
    app(GuestCart::class)->add($session, $cup->id, 2);
    $checkout = app(DevelopmentCheckoutService::class);
    $checkout->address($session, ['first_name' => 'Recovery', 'last_name' => 'Demo', 'contact_email' => 'buyer@example.test', 'contact_phone' => '+385910000000', 'line_one' => 'Testna ulica 1', 'city' => 'Zagreb', 'postcode' => '10000', 'country_id' => Country::query()->where('iso2', 'HR')->sole()->id]);
    $checkout->review($session);
    $pending = $checkout->place($session, $session->get('oneshop.checkout_quote'));
    $checkout->simulate($session, $pending->id, 'paid');
    $session->save();
    ensure((int) Order::query()->sole()->getRawOriginal('total') === 3500 && $cup->fresh()->stock === 18, 'Synthetic order/stock fixture failed.');
    file_put_contents($work.'/app_key', config('app.key'));
    file_put_contents($work.'/expected.json', json_encode(fingerprint(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo "Synthetic database, encrypted session/MFA, public photos and private asset prepared.\n";
    exit(0);
}

$operator = User::query()->where('email', 'recovery@example.test')->sole();
if ($mode === 'wrong-key') {
    try {
        $operator->getAppAuthenticationSecret();
    } catch (DecryptException) {
        $report = json_decode(file_get_contents($work.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
        $report['wrong_key_rejected'] = true;
        file_put_contents($work.'/report.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        echo "A different installation key was correctly rejected.\n";
        exit(0);
    }
    throw new RuntimeException('Encrypted data unexpectedly accepted a different key.');
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
ensure(count($owned) === 1 && app(DevelopmentCheckoutService::class)->owned($session, $owned[0])->status === 'paid', 'Encrypted guest checkout session did not survive.');
$asset = DigitalAsset::query()->sole();
ob_start();
app(DigitalAssetEditor::class)->download($asset)->sendContent();
$download = ob_get_clean();
ensure(hash('sha256', $download) === $asset->sha256, 'Restored authorized private download failed.');
$order = Order::query()->sole();
ensure($order->user_id === null && (int) $order->getRawOriginal('total') === 3500, 'Historical guest order changed.');
$pdf = app(OrderSummary::class)->pdf($order);
ensure(str_starts_with($pdf, '%PDF-'), 'Restored order summary did not render.');

$cup = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
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
file_put_contents($work.'/report.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Restored rows, files, encrypted data, internal PDF, index and sequence verified.\n";
