<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Lunar\Models\TaxClass;
use Tests\TestCase;

class InitializeShopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertContains(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb']);
        config()->set('app.faker_locale', 'en_US');
        config()->set('oneshop.primary_locale', 'hr');
        config()->set('oneshop.enabled_locales', ['hr', 'en']);
        config()->set('oneshop.currency', 'EUR');
    }

    public function test_initialization_is_repeatable_and_creates_only_explicit_base_configuration(): void
    {
        $this->artisan('oneshop:initialize')->assertSuccessful();

        $languageIds = Language::query()->orderBy('code')->pluck('id')->all();
        $currencyId = Currency::query()->sole()->id;
        $channelId = Channel::query()->sole()->id;
        $groupId = CustomerGroup::query()->sole()->id;

        $this->artisan('oneshop:initialize')->assertSuccessful();

        $this->assertSame(['en', 'hr'], Language::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame($languageIds, Language::query()->orderBy('code')->pluck('id')->all());
        $this->assertSame('hr', Language::query()->where('default', true)->sole()->code);
        $currency = Currency::query()->sole();
        $this->assertSame($currencyId, $currency->id);
        $this->assertSame('EUR', $currency->code);
        $this->assertSame(2, $currency->decimal_places);
        $this->assertTrue($currency->enabled);
        $this->assertTrue($currency->default);
        $this->assertSame($channelId, Channel::query()->where('default', true)->sole()->id);
        $this->assertSame($groupId, CustomerGroup::query()->where('default', true)->sole()->id);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, ProductType::query()->count());
        $this->assertSame(0, TaxClass::query()->count());
    }

    public function test_existing_compatible_merchant_names_urls_and_defaults_are_preserved(): void
    {
        Language::factory()->create(['code' => 'hr', 'name' => 'Naš hrvatski', 'default' => true]);
        Language::factory()->create(['code' => 'en', 'name' => 'Our English', 'default' => false]);
        Currency::factory()->create([
            'code' => 'EUR',
            'name' => 'Merchant Euro',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'enabled' => true,
            'default' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Merchant storefront',
            'handle' => 'merchant-web',
            'url' => 'https://merchant.example',
            'default' => true,
        ]);
        $group = CustomerGroup::factory()->create([
            'name' => 'Naši kupci',
            'handle' => 'our-customers',
            'default' => true,
        ]);

        $this->artisan('oneshop:initialize')->assertSuccessful();

        $this->assertSame('Naš hrvatski', Language::query()->where('code', 'hr')->sole()->name);
        $this->assertSame('Our English', Language::query()->where('code', 'en')->sole()->name);
        $this->assertSame('Merchant Euro', Currency::query()->sole()->name);
        $this->assertSame($channel->id, Channel::query()->sole()->id);
        $this->assertSame('merchant-web', $channel->fresh()->handle);
        $this->assertSame('https://merchant.example', $channel->fresh()->url);
        $this->assertSame($group->id, CustomerGroup::query()->sole()->id);
        $this->assertSame('Naši kupci', $group->fresh()->name);
    }

    public function test_a_later_currency_conflict_rolls_back_new_languages_without_rewriting_currency(): void
    {
        $currency = Currency::factory()->create([
            'code' => 'EUR',
            'decimal_places' => 3,
            'exchange_rate' => 1,
            'enabled' => true,
            'default' => true,
        ]);

        $this->artisan('oneshop:initialize')
            ->expectsOutputToContain('exactly two decimal places')
            ->assertFailed();

        $this->assertSame(0, Language::query()->count());
        $this->assertSame(0, Channel::query()->count());
        $this->assertSame(0, CustomerGroup::query()->count());
        $this->assertSame(3, $currency->fresh()->decimal_places);
    }

    public function test_conflicting_default_language_is_not_replaced(): void
    {
        $language = Language::factory()->create(['code' => 'en', 'default' => true]);

        $this->artisan('oneshop:initialize')
            ->expectsOutputToContain('existing default language conflicts')
            ->assertFailed();

        $this->assertSame($language->id, Language::query()->sole()->id);
        $this->assertSame(0, Currency::query()->count());
    }

    public function test_multiple_defaults_are_refused_without_partial_initialization(): void
    {
        CustomerGroup::factory()->create(['handle' => 'retail', 'default' => true]);
        CustomerGroup::factory()->create(['handle' => 'legacy', 'default' => false]);
        CustomerGroup::query()->update(['default' => true]);

        $this->artisan('oneshop:initialize')
            ->expectsOutputToContain('Multiple default customer group records')
            ->assertFailed();

        $this->assertSame(2, CustomerGroup::query()->where('default', true)->count());
        $this->assertSame(0, Language::query()->count());
        $this->assertSame(0, Currency::query()->count());
        $this->assertSame(0, Channel::query()->count());
    }

    public function test_primary_language_is_taken_from_valid_explicit_configuration(): void
    {
        config()->set('oneshop.primary_locale', 'en');

        $this->artisan('oneshop:initialize')->assertSuccessful();

        $this->assertSame('en', Language::query()->where('default', true)->sole()->code);
        $this->assertSame(2, Language::query()->count());
    }

    public function test_languages_outside_the_enabled_list_are_not_created_or_deleted(): void
    {
        config()->set('oneshop.enabled_locales', ['hr']);
        $existing = Language::factory()->create(['code' => 'de', 'name' => 'Custom German', 'default' => false]);

        $this->artisan('oneshop:initialize')->assertSuccessful();

        $this->assertSame(['de', 'hr'], Language::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame('Custom German', $existing->fresh()->name);
    }

    public function test_invalid_enabled_language_configuration_fails_before_writing_records(): void
    {
        config()->set('oneshop.enabled_locales', ['en']);

        $this->artisan('oneshop:initialize')
            ->expectsOutputToContain('include the configured primary locale')
            ->assertFailed();

        $this->assertSame(0, Language::query()->count());
        $this->assertSame(0, Currency::query()->count());
    }
}
