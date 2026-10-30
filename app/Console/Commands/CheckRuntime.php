<?php

namespace App\Console\Commands;

use App\Digital\DigitalAssetEditor;
use App\Storefront\StoreTheme;
use App\Support\ShopTime;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lunar\Models\Currency;
use Lunar\Models\Language;
use Throwable;

class CheckRuntime extends Command
{
    protected $signature = 'oneshop:check-runtime {--target=docker : docker, cpanel or fastpanel} {--production : Check production application settings}';

    protected $description = 'Check the actual PHP, database, application and asset prerequisites without changing configuration';

    private bool $failed = false;

    public function handle(): int
    {
        $this->failed = false;
        $target = $this->option('target');
        if (! in_array($target, ['docker', 'cpanel', 'fastpanel'], true)) {
            $this->error('Target must be docker, cpanel or fastpanel.');

            return self::FAILURE;
        }
        $this->line('Requested deployment target: '.$target.' (the panel itself is not detected or certified).');
        $this->check(version_compare(PHP_VERSION, '8.5.0', '>='), 'PHP 8.5+: '.PHP_VERSION);
        try {
            $this->check(true, 'Display time zone: '.ShopTime::zone());
        } catch (\LogicException) {
            $this->check(false, 'Configure ONESHOP_DISPLAY_TIMEZONE with a valid IANA time zone.');
        }
        $theme = config('oneshop.theme');
        $this->check(is_string($theme) && StoreTheme::tryFrom($theme) !== null, 'Prepared storefront theme: '.StoreTheme::configured()->value);
        foreach (['bcmath', 'ctype', 'curl', 'dom', 'exif', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'xml'] as $extension) {
            $this->check(extension_loaded($extension), 'PHP extension: '.$extension);
        }
        $this->check(function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng') && function_exists('imagecreatefromwebp') && function_exists('imagewebp'), 'GD supports gallery JPEG, PNG and WebP conversions');
        $uploadLimit = ini_parse_quantity((string) ini_get('upload_max_filesize'));
        $postLimit = ini_parse_quantity((string) ini_get('post_max_size'));
        $requiredUpload = DigitalAssetEditor::MAX_KILOBYTES * 1024;
        $this->check((bool) ini_get('file_uploads') && $uploadLimit >= $requiredUpload, 'PHP uploads support private files up to 10 MB');
        $this->check($postLimit === 0 || $postLimit >= $requiredUpload + 2 * 1024 * 1024, 'PHP post_max_size permits uploads with request overhead');
        $driver = DB::connection()->getDriverName();
        $this->check(in_array($driver, ['pgsql', 'mariadb', 'mysql'], true), 'Database driver: '.$driver);
        $this->check(extension_loaded($driver === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql'), 'Matching PDO driver installed');
        try {
            $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;
            $minimum = $driver === 'pgsql' ? '18.0' : '10.11';
            preg_match('/\d+\.\d+(?:\.\d+)?/', $version, $matches);
            $supported = $driver === 'pgsql' || str_contains($version, 'MariaDB');
            $this->check($supported && isset($matches[0]) && version_compare($matches[0], $minimum, '>='), 'Supported database version: '.$version);
            $isolation = (string) DB::selectOne($driver === 'pgsql'
                ? "SELECT current_setting('transaction_isolation') AS isolation_level"
                : (str_contains($version, 'MariaDB') ? 'SELECT @@session.tx_isolation AS isolation_level'
                    : 'SELECT @@session.transaction_isolation AS isolation_level'))->isolation_level;
            $this->check(strtoupper(str_replace('-', ' ', $isolation)) === 'READ COMMITTED',
                'Application database session isolation: '.$isolation);
            $migrator = app(Migrator::class);
            $ran = $migrator->getRepository()->getRan();
            $files = $migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]);
            $this->check(array_diff(array_keys($files), $ran) === [], 'All application and package migrations applied');
            $this->check(Currency::query()->where('code', 'EUR')->where('enabled', true)->exists(), 'Shop currency initialized');
            $this->check(Language::query()->where('code', config('oneshop.primary_locale'))->where('default', true)->exists(), 'Primary language initialized');
            foreach (['jobs', 'failed_jobs', 'cache', 'cache_locks', 'sessions'] as $table) {
                $this->check(Schema::hasTable($table), 'Runtime table: '.$table);
            }
        } catch (Throwable) {
            $this->check(false, 'Database connection, migrations and initialization must be available. No credentials are printed.');
        }
        try {
            app('encrypter');
            $this->check(true, 'Application encryption key configured');
        } catch (Throwable) {
            $this->check(false, 'Application encryption key missing or invalid');
        }
        foreach ([storage_path('framework/cache'), storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs'), storage_path('app/private'), storage_path('app/public'), base_path('bootstrap/cache')] as $path) {
            $this->check(is_dir($path) && is_writable($path), 'Writable: '.str_replace(base_path().'/', '', $path));
        }
        $this->check(is_link(public_path('storage')) && realpath(public_path('storage')) === realpath(storage_path('app/public')), 'Public storage link exposes only public media');
        $manifest = public_path('build/manifest.json');
        $assets = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
        $assetFiles = [];
        if (is_array($assets)) {
            foreach ($assets as $entry) {
                $assetFiles[] = $entry['file'] ?? '';
                array_push($assetFiles, ...($entry['css'] ?? []));
            }
        }
        $this->check($assetFiles !== [] && array_all($assetFiles, fn ($file) => is_string($file) && $file !== '' && is_file(public_path('build/'.$file))), 'Built frontend assets available');
        if ($this->option('production')) {
            $this->check(config('app.env') === 'production', 'APP_ENV=production');
            $this->check(! config('app.debug'), 'APP_DEBUG=false');
            $this->check(str_starts_with((string) config('app.url'), 'https://'), 'HTTPS application URL');
            $this->check((bool) config('session.secure'), 'Secure session cookies');
        }
        if ($target !== 'docker') {
            $this->check(config('queue.default') === 'database', 'Native hosting uses database queue');
            $this->check(in_array(config('cache.default'), ['database', 'file'], true), 'Native hosting cache does not require Redis');
            $this->check(in_array(config('session.driver'), ['database', 'file'], true), 'Native hosting sessions do not require Redis');
            $this->warn('Configure a once-per-minute schedule:run cron using the same PHP version. Cron/web-server configuration is not verified by this command.');
        }

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function check(bool $passed, string $message): void
    {
        if ($passed) {
            $this->line('OK: '.$message);
        } else {
            $this->failed = true;
            $this->error('FAIL: '.$message);
        }
    }
}
