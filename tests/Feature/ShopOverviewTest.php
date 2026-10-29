<?php

namespace Tests\Feature;

use App\Filament\Widgets\ShopOverview;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class ShopOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US']);
        app()->setLocale('hr');
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_dashboard_counts_live_catalog_and_placed_orders_without_revenue_or_private_data(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($admin);
        ProductVariant::query()->where('sku', 'DEMO-CUP')->update(['stock' => 0]);
        ProductVariant::query()->where('sku', 'DEMO-BAG')->sole()->delete();
        Order::factory()->create(['placed_at' => null, 'currency_code' => 'EUR']);
        Order::factory()->create(['placed_at' => now(), 'currency_code' => 'EUR', 'total' => 987654321,
            'meta' => ['oneshop_mode' => 'demo', 'secret' => 'PRIVATE_ORDER_PAYLOAD']]);
        Livewire::test(ShopOverview::class)->assertSee('3 objavljeno')->assertSee('Praćenih SKU-ova bez zalihe: 1')
            ->assertSee('Testnih narudžbi: 1')->assertSee('Aktivni načini dostave')->assertDontSee('PRIVATE_ORDER_PAYLOAD')->assertDontSee('987654321');
        $this->get('/admin')->assertOk()->assertSee('Pregled trgovine');
    }

    public function test_widget_denies_guests_customers_and_unenrolled_admins_and_rechecks_revoked_access(): void
    {
        $this->assertFalse(ShopOverview::canView());
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->assertFalse(ShopOverview::canView());
        Livewire::test(ShopOverview::class)->assertForbidden();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);
        $this->assertFalse(ShopOverview::canView());
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->assertTrue(ShopOverview::canView());
        $widget = Livewire::test(ShopOverview::class)->assertOk();
        $admin->forceFill(['is_admin' => false])->save();
        $widget->call('$refresh')->assertForbidden();
    }
}
