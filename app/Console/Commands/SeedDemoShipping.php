<?php

namespace App\Console\Commands;

use App\Models\ShippingMethod;
use Illuminate\Console\Command;
use Lunar\Models\Country;
use Lunar\Models\TaxClass;

class SeedDemoShipping extends Command
{
    protected $signature = 'oneshop:demo-shipping';

    protected $description = 'Explicitly create a local-only flat shipping fixture without changing existing methods';

    public const CODE = 'd2a7daa2-3177-4b45-9caa-d3ca585554e0';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Demo shipping is allowed only in local/testing environments.');

            return self::FAILURE;
        }
        if (ShippingMethod::query()->where('code', self::CODE)->exists()) {
            $this->info('Demo shipping already exists. Existing edits were preserved.');

            return self::SUCCESS;
        }
        $taxClass = TaxClass::query()->where('name', 'DEMO — testni porez 25%')->first();
        if (! $taxClass || ! Country::query()->where('iso2', 'HR')->exists()) {
            $this->error('Create the development demo catalogue first. No merchant tax settings were changed.');

            return self::FAILURE;
        }
        $method = new ShippingMethod;
        $method->forceFill(['code' => self::CODE, 'name' => 'DEMO — Standardna dostava',
            'description' => 'Testna dostava na adresu u Hrvatskoj. Nema povezivanja sa stvarnim dostavljačem.',
            'countries' => ['HR'], 'tax_class_id' => $taxClass->id, 'enabled' => true, 'version' => 1,
            'price_minor' => config('lunar.pricing.stored_inclusive_of_tax') ? 500 : 400])->save();
        $this->info('Demo shipping created: EUR 5.00 including the demo 25% tax. This is not an agreed merchant tariff.');

        return self::SUCCESS;
    }
}
