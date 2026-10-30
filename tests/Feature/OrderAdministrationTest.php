<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\LinesRelationManager;
use App\Filament\Resources\Orders\RelationManagers\TransactionsRelationManager;
use App\Models\User;
use App\Orders\OrderMoney;
use App\Orders\OrderSummary;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use Lunar\Models\OrderAddress;
use Lunar\Models\OrderLine;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;
use Tests\TestCase;

class OrderAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.faker_locale' => 'en_US']);
        $this->artisan('oneshop:initialize')->assertSuccessful();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->order = Order::factory()->create([
            'currency_code' => 'EUR', 'compare_currency_code' => 'EUR', 'placed_at' => now(),
            'reference' => 'ORDER-HISTORY', 'sub_total' => 1000, 'discount_total' => 0,
            'shipping_total' => 0, 'tax_total' => 235, 'total' => 1235,
            'meta' => ['secret' => 'NEVER_RENDER_ORDER_SECRET'],
        ]);
        OrderAddress::factory()->create([
            'order_id' => $this->order->id, 'type' => 'billing', 'first_name' => 'Povijesni', 'last_name' => 'Kupac',
            'company_name' => 'Povijesna tvrtka', 'tax_identifier' => '12345678901',
            'line_one' => 'Izvorna ulica 12', 'contact_email' => 'history@example.test',
        ]);
    }

    public function test_admin_lists_and_filters_only_placed_orders(): void
    {
        $draft = Order::factory()->create(['currency_code' => 'EUR', 'placed_at' => null]);
        $paid = Order::factory()->create(['currency_code' => 'EUR', 'placed_at' => now(), 'status' => 'payment-received']);
        $this->get('/admin/orders')->assertOk()->assertDontSee('NEVER_RENDER_ORDER_SECRET');
        Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$this->order, $paid])
            ->assertCanNotSeeTableRecords([$draft])->assertTableActionDoesNotExist(EditAction::class)
            ->filterTable('status', 'payment-received')->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$this->order]);
        $this->get('/admin/orders/'.$draft->id)->assertNotFound();
    }

    public function test_order_details_use_address_snapshots_and_do_not_expose_metadata(): void
    {
        $customer = User::factory()->create(['name' => 'Current profile name', 'email' => 'current-profile@example.test']);
        $this->order->update(['user_id' => $customer->id]);
        $before = $this->order->fresh()->getRawOriginal();
        $this->get('/admin/orders/'.$this->order->id)->assertOk()->assertSee('Povijesna tvrtka')
            ->assertSee('Izvorna ulica 12')->assertSee('history@example.test')->assertSee('12,35 EUR')
            ->assertDontSee('Current profile name')->assertDontSee('current-profile@example.test')->assertDontSee('NEVER_RENDER_ORDER_SECRET');
        Livewire::test(ViewOrder::class, ['record' => $this->order->id])->assertSet('data', []);
        $this->assertSame($before, $this->order->fresh()->getRawOriginal());
    }

    public function test_sandbox_orders_are_marked_as_test_data_in_the_list_details_and_pdf(): void
    {
        $this->order->update(['meta' => ['oneshop_mode' => 'stripe-test'], 'status' => 'payment-received']);
        $this->get('/admin/orders/'.$this->order->id)->assertOk()
            ->assertSee('Stripe sandbox — bez stvarne naplate i dostave')->assertSee('Stripe testna uplata potvrđena');
        $html = app(OrderSummary::class)->html($this->order);
        $this->assertStringContainsString('TESTNA NARUDŽBA', $html);
        $this->assertStringContainsString('Stripe sandbox', $html);
        $this->assertStringContainsString('Bez stvarne naplate i dostave', $html);
    }

    public function test_order_mode_filter_separates_simulator_sandbox_and_standard_and_searches_email(): void
    {
        $demo = Order::factory()->create(['placed_at' => now(), 'currency_code' => 'EUR', 'meta' => ['oneshop_mode' => 'demo']]);
        $stripe = Order::factory()->create(['placed_at' => now(), 'currency_code' => 'EUR', 'meta' => ['oneshop_mode' => 'stripe-test']]);
        $nullMode = Order::factory()->create(['placed_at' => now(), 'currency_code' => 'EUR', 'meta' => ['oneshop_mode' => null]]);
        Livewire::test(ListOrders::class)->filterTable('mode', 'stripe-test')
            ->assertCanSeeTableRecords([$stripe])->assertCanNotSeeTableRecords([$demo, $this->order, $nullMode]);
        Livewire::test(ListOrders::class)->filterTable('mode', 'demo')
            ->assertCanSeeTableRecords([$demo])->assertCanNotSeeTableRecords([$stripe, $this->order, $nullMode]);
        Livewire::test(ListOrders::class)->filterTable('mode', 'standard')
            ->assertCanSeeTableRecords([$this->order, $nullMode])->assertCanNotSeeTableRecords([$demo, $stripe]);
        Livewire::test(ListOrders::class)->searchTable('history@example.test')
            ->assertCanSeeTableRecords([$this->order])->assertCanNotSeeTableRecords([$demo, $stripe, $nullMode]);
    }

    public function test_lines_show_historical_descriptions_and_amounts_after_catalog_changes(): void
    {
        $variant = ProductVariant::factory()->create(['sku' => 'ORIGINAL-SKU']);
        $line = OrderLine::factory()->create([
            'order_id' => $this->order->id, 'purchasable_id' => $variant->id,
            'description' => 'Povijesni naziv majice', 'identifier' => 'ORIGINAL-SKU', 'option' => 'Plava / M',
            'unit_price' => 1000, 'sub_total' => 1000, 'discount_total' => 0, 'tax_total' => 235, 'total' => 1235,
        ]);
        $variant->update(['sku' => 'CURRENT-SKU']);
        $variant->product->update(['attribute_data' => collect(['name' => new TranslatedText(['hr' => 'Današnji naziv'])])]);
        Livewire::test(LinesRelationManager::class, ['ownerRecord' => $this->order, 'pageClass' => ViewOrder::class])
            ->assertCanSeeTableRecords([$line])->assertSee('Povijesni naziv majice')->assertSee('ORIGINAL-SKU')
            ->assertSee('12,35 EUR')->assertDontSee('CURRENT-SKU')->assertDontSee('Današnji naziv')
            ->assertTableActionDoesNotExist(CreateAction::class)->assertTableActionDoesNotExist(EditAction::class)->assertTableActionDoesNotExist(DeleteAction::class);
        $this->assertSame('Povijesni naziv majice', $line->fresh()->description);
    }

    public function test_transactions_are_scoped_read_only_and_hide_private_payloads(): void
    {
        $transaction = Transaction::factory()->create([
            'order_id' => $this->order->id, 'type' => 'capture', 'amount' => 1235,
            'reference' => 'PAYMENT-HISTORY', 'meta' => ['token' => 'NEVER_RENDER_TRANSACTION_SECRET'],
        ]);
        $foreign = Transaction::factory()->create(['order_id' => Order::factory()->create(['currency_code' => 'EUR'])->id, 'reference' => 'OTHER-ORDER-PAYMENT']);
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $this->order, 'pageClass' => ViewOrder::class])
            ->assertCanSeeTableRecords([$transaction])->assertCanNotSeeTableRecords([$foreign])
            ->assertSee('PAYMENT-HISTORY')->assertSee('12,35 EUR')->assertDontSee('NEVER_RENDER_TRANSACTION_SECRET')
            ->assertDontSee('OTHER-ORDER-PAYMENT')->assertTableActionDoesNotExist(CreateAction::class)
            ->assertTableActionDoesNotExist(EditAction::class)->assertTableActionDoesNotExist(DeleteAction::class);
    }

    public function test_amount_display_preserves_sign_precision_and_the_order_currency(): void
    {
        $snapshot = $this->order->fresh();
        $snapshot->setRawAttributes([...$snapshot->getRawOriginal(), 'total' => -123], true);
        $this->assertSame('-1,23 EUR', OrderMoney::format($snapshot, 'total'));
        Currency::query()->create(['code' => 'KWD', 'name' => 'Kuwaiti dinar', 'exchange_rate' => 1, 'decimal_places' => 3, 'default' => false, 'enabled' => true]);
        $snapshot = $this->order->fresh();
        $snapshot->setRawAttributes([...$snapshot->getRawOriginal(), 'currency_code' => 'KWD', 'total' => 123456789012345], true);
        $this->assertSame('123456789012,345 KWD', OrderMoney::format($snapshot, 'total'));
        $this->order->update(['currency_code' => 'ZZZ', 'total' => 123]);
        $this->assertSame('123 ZZZ (najmanje jedinice)', OrderMoney::format($this->order->fresh(), 'total'));
    }

    public function test_missing_or_invalid_records_and_write_routes_are_not_exposed_even_to_superadmin(): void
    {
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->assertFalse(OrderResource::canCreate());
        $this->assertFalse(OrderResource::canEdit($this->order));
        $this->assertFalse(OrderResource::canDelete($this->order));
        $this->get('/admin/orders/create')->assertNotFound();
        $this->get('/admin/orders/'.$this->order->id.'/edit')->assertNotFound();
        $this->get('/admin/orders/999999999999999999999999')->assertNotFound();
        $this->get('/admin/orders/999999')->assertNotFound();
    }

    public function test_guests_customers_and_admins_without_mfa_cannot_read_order_data(): void
    {
        $this->app['auth']->forgetGuards();
        $this->get('/admin/orders')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/orders/'.$this->order->id)->assertForbidden();
        Livewire::test(LinesRelationManager::class, ['ownerRecord' => $this->order, 'pageClass' => ViewOrder::class])->assertStatus(403);
        $unenrolled = User::factory()->create();
        $unenrolled->forceFill(['is_admin' => true])->save();
        $this->actingAs($unenrolled)->get('/admin/orders')->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
    }

    public function test_order_access_is_checked_again_on_livewire_requests_after_permission_revocation(): void
    {
        $component = Livewire::test(ViewOrder::class, ['record' => $this->order->id]);
        $this->admin->forceFill(['is_admin' => false])->save();
        $component->call('$refresh')->assertStatus(403);
    }
}
