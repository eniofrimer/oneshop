<?php

namespace App\Console\Commands;

use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;

class InitializeShop extends Command
{
    protected $signature = 'oneshop:initialize';

    protected $description = 'Explicitly create missing base shop records without replacing merchant configuration';

    private int $createdRecords = 0;

    public function handle(): int
    {
        $this->createdRecords = 0;

        try {
            [$primaryLocale, $locales] = $this->validatedLocales();

            if (config('oneshop.currency') !== 'EUR') {
                throw new DomainException('This initial shop scope requires oneshop.currency to be EUR.');
            }

            if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
                throw new DomainException('Shop initialization requires PostgreSQL or MariaDB.');
            }

            foreach ([new Language, new Currency, new Channel, new CustomerGroup] as $model) {
                if (! Schema::hasTable($model->getTable())) {
                    throw new DomainException('Commerce tables are missing. Run php artisan migrate before oneshop:initialize.');
                }
            }

            $namedLock = DB::connection()->getDriverName() !== 'pgsql';
            $lockName = 'oneshop:init:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 40);
            if ($namedLock && (int) DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName])->acquired !== 1) {
                throw new DomainException('Another shop initialization is running. Retry after it completes.');
            }

            try {
                DB::transaction(function () use ($primaryLocale, $locales, $namedLock): void {
                    if (! $namedLock) {
                        DB::select('SELECT pg_advisory_xact_lock(?)', [1869509488]);
                    }
                    $this->ensureLanguages($primaryLocale, $locales);
                    $this->ensureCurrency();
                    $this->ensureChannel();
                    $this->ensureCustomerGroup();
                });
            } finally {
                // MariaDB's named lock belongs to the connection, not the transaction.
                if ($namedLock) {
                    DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
                }
            }
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());
            $this->line('No initialization changes were committed. Existing records were preserved.');

            return self::FAILURE;
        } catch (QueryException $exception) {
            report($exception);
            $this->error('Database initialization failed. Check the connection and completed migrations.');
            $this->line('No initialization changes were committed.');

            return self::FAILURE;
        }

        $this->info("Shop initialization complete: {$this->createdRecords} records created. Existing compatible records were preserved.");

        return self::SUCCESS;
    }

    /** @return array{string, list<string>} */
    private function validatedLocales(): array
    {
        $primary = $this->validatedLocale(config('oneshop.primary_locale'));
        $locales = config('oneshop.enabled_locales');

        if (! is_array($locales) || ! array_is_list($locales) || $locales === []) {
            throw new DomainException('Configure oneshop.primary_locale and a non-empty list of oneshop.enabled_locales.');
        }

        $validatedLocales = [];

        foreach ($locales as $locale) {
            $validatedLocales[] = $this->validatedLocale($locale);
        }

        if (! in_array($primary, $validatedLocales, true) || count(array_unique($validatedLocales)) !== count($validatedLocales)) {
            throw new DomainException('Enabled locales must be unique and include the configured primary locale.');
        }

        return [$primary, $validatedLocales];
    }

    private function validatedLocale(mixed $locale): string
    {
        if (! is_string($locale) || strlen($locale) > 255 || ! preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $locale)) {
            throw new DomainException('Configured locales must be language codes such as hr, en or en_GB, at most 255 characters.');
        }

        return $locale;
    }

    /** @param list<string> $locales */
    private function ensureLanguages(string $primary, array $locales): void
    {
        $default = $this->singleDefault(Language::class, 'language');

        if ($default !== null && $default->getAttribute('code') !== $primary) {
            throw new DomainException('The existing default language conflicts with oneshop.primary_locale. Resolve it explicitly before initializing.');
        }

        foreach (array_unique([$primary, ...$locales]) as $locale) {
            $language = Language::query()->where('code', $locale)->lockForUpdate()->first();

            if ($language !== null) {
                if ($locale === $primary && ! $language->getAttribute('default')) {
                    throw new DomainException('The configured primary language exists without being the default. Select the default explicitly before initializing.');
                }

                continue;
            }

            Language::query()->create([
                'code' => $locale,
                'name' => \Locale::getDisplayName($locale, $locale) ?: $locale,
                'default' => $locale === $primary,
            ]);
            $this->createdRecords++;
        }
    }

    private function ensureCurrency(): void
    {
        $default = $this->singleDefault(Currency::class, 'currency');

        if ($default !== null && $default->getAttribute('code') !== 'EUR') {
            throw new DomainException('The existing default currency conflicts with EUR. Resolve it explicitly before initializing.');
        }

        $currency = Currency::query()->where('code', 'EUR')->lockForUpdate()->first();

        if ($currency !== null) {
            if (! $currency->default || ! $currency->enabled || $currency->decimal_places !== 2) {
                throw new DomainException('Existing EUR must already be enabled, default and configured with exactly two decimal places. It was not modified.');
            }

            if (bccomp((string) $currency->exchange_rate, '1', 8) !== 0) {
                throw new DomainException('The existing default EUR exchange rate must be 1. It was not modified.');
            }

            return;
        }

        Currency::query()->create([
            'code' => 'EUR',
            'name' => 'Euro',
            'exchange_rate' => 1,
            'decimal_places' => 2,
            'enabled' => true,
            'default' => true,
        ]);
        $this->createdRecords++;
    }

    private function ensureChannel(): void
    {
        if ($this->singleDefault(Channel::class, 'channel') !== null) {
            return;
        }

        if (Channel::withTrashed()->exists()) {
            throw new DomainException('Channel records exist without an active default. Select or restore the default explicitly before initializing.');
        }

        Channel::query()->create([
            'name' => 'Web trgovina',
            'handle' => 'web',
            'url' => config('app.url'),
            'default' => true,
        ]);
        $this->createdRecords++;
    }

    private function ensureCustomerGroup(): void
    {
        if ($this->singleDefault(CustomerGroup::class, 'customer group') !== null) {
            return;
        }

        if (CustomerGroup::query()->exists()) {
            throw new DomainException('Customer groups exist without a default. Select the default explicitly before initializing.');
        }

        CustomerGroup::query()->create([
            'name' => 'Maloprodaja',
            'handle' => 'retail',
            'default' => true,
        ]);
        $this->createdRecords++;
    }

    /** @param class-string<Model> $modelClass */
    private function singleDefault(string $modelClass, string $label): ?Model
    {
        $defaults = $modelClass::query()->where('default', true)->lockForUpdate()->get();

        if ($defaults->count() > 1) {
            throw new DomainException("Multiple default {$label} records exist. Resolve the conflicting defaults before initializing.");
        }

        return $defaults->first();
    }
}
