<?php

namespace Tests\Feature;

use App\Filament\Widgets\RecentOrders;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Lunar\Models\Order;
use Tests\TestCase;

class RecentOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US']);
        $this->artisan('oneshop:initialize')->assertSuccessful();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_dashboard_shows_only_placed_order_snapshots_and_test_labels(): void
    {
        $owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($owner);
        $placed = Order::factory()->create(['placed_at' => now(), 'currency_code' => 'EUR', 'total' => 1235,
            'status' => 'payment-review', 'meta' => ['oneshop_mode' => 'stripe-test', 'private' => 'PRIVATE_RECENT_ORDER_METADATA']]);
        $draft = Order::factory()->create(['placed_at' => null, 'currency_code' => 'EUR']);
        $before = $placed->fresh()->getRawOriginal();
        Livewire::test(RecentOrders::class)->assertCanSeeTableRecords([$placed])->assertCanNotSeeTableRecords([$draft])
            ->assertSee('12,35 EUR')->assertSee('Stripe sandbox')->assertSee('Stripe testna uplata zahtijeva provjeru')
            ->assertDontSee('PRIVATE_RECENT_ORDER_METADATA');
        $this->assertSame($before, $placed->fresh()->getRawOriginal());
    }

    public function test_widget_rechecks_permission_after_access_is_revoked(): void
    {
        Livewire::test(RecentOrders::class)->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Livewire::test(RecentOrders::class)->assertForbidden();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $widget = Livewire::test(RecentOrders::class)->assertOk();
        $admin->forceFill(['is_admin' => false])->save();
        $widget->call('$refresh')->assertForbidden();
    }
}
