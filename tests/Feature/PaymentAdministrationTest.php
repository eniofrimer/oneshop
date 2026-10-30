<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Lunar\Models\Order;
use Lunar\Models\Transaction;
use Tests\TestCase;

class PaymentAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US']);
        $this->artisan('oneshop:initialize')->assertSuccessful();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_ledger_lists_placed_orders_and_hides_drafts_and_private_payloads(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($admin);
        $placed = Order::factory()->create(['currency_code' => 'EUR', 'placed_at' => now(), 'reference' => 'PAYMENT-HISTORY']);
        $draft = Order::factory()->create(['currency_code' => 'EUR', 'placed_at' => null]);
        $payment = Transaction::factory()->create(['order_id' => $placed->id, 'driver' => 'oneshop-demo', 'amount' => 1235,
            'notes' => 'DO_NOT_RENDER_PAYMENT_NOTES', 'meta' => ['private' => 'DO_NOT_RENDER_PAYMENT_PAYLOAD']]);
        $hidden = Transaction::factory()->create(['order_id' => $draft->id]);
        $this->get('/admin/payments')->assertOk()->assertSee('Testna simulacija')->assertDontSee('DO_NOT_RENDER_PAYMENT_PAYLOAD');
        Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$payment])->assertCanNotSeeTableRecords([$hidden])
            ->assertTableActionDoesNotExist(EditAction::class)->assertTableActionDoesNotExist(DeleteAction::class);
        $this->get('/admin/payments/'.$payment->id)->assertOk()->assertSee('12,35 EUR')->assertSee('PAYMENT-HISTORY')
            ->assertDontSee('DO_NOT_RENDER_PAYMENT_PAYLOAD')->assertDontSee('DO_NOT_RENDER_PAYMENT_NOTES');
        Livewire::test(ViewPayment::class, ['record' => $payment->id])->assertSet('data', []);
        $this->get('/admin/payments/'.$hidden->id)->assertNotFound();
        $this->get('/admin/payments/not-a-number')->assertNotFound();
        $this->get('/admin/payments/create')->assertNotFound();
        $this->get('/admin/payments/'.$payment->id.'/edit')->assertNotFound();
        $this->assertFalse(PaymentResource::canEdit($payment));
        $this->assertFalse(PaymentResource::canDelete($payment));
    }

    public function test_customers_and_admins_without_mfa_cannot_open_the_payment_ledger(): void
    {
        $customer = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        foreach ([$customer, $admin] as $user) {
            $this->actingAs($user);
            $this->assertFalse(PaymentResource::canViewAny());
            $response = $this->get('/admin/payments');
            $this->assertContains($response->status(), [302, 403]);
        }
    }

    public function test_outcome_filters_and_labels_preserve_the_stored_payment_history(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($admin);
        $order = Order::factory()->create(['currency_code' => 'EUR', 'placed_at' => now(), 'reference' => 'OUTCOME-HISTORY']);
        $paid = Transaction::factory()->create(['order_id' => $order->id, 'driver' => 'oneshop-demo', 'type' => 'capture', 'status' => 'paid', 'success' => true]);
        $declined = Transaction::factory()->create(['order_id' => $order->id, 'driver' => 'oneshop-demo', 'type' => 'capture', 'status' => 'declined', 'success' => false]);
        $unknown = Transaction::factory()->create(['order_id' => $order->id, 'driver' => 'other-processor', 'type' => 'intent', 'status' => 'PROVIDER-STATE', 'success' => false]);
        $draft = Transaction::factory()->create(['order_id' => Order::factory()->create(['currency_code' => 'EUR', 'placed_at' => null])->id, 'success' => false]);
        $snapshots = collect([$paid, $declined, $unknown])->map(fn (Transaction $payment) => $payment->fresh()->getRawOriginal());

        Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$paid, $declined, $unknown])->assertCanNotSeeTableRecords([$draft])
            ->assertSee('Testno potvrđeno')->assertSee('Testno odbijeno')->assertSee('PROVIDER-STATE')
            ->filterTable('success', true)->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$declined, $unknown, $draft])
            ->filterTable('success', false)->assertCanSeeTableRecords([$declined, $unknown])->assertCanNotSeeTableRecords([$paid, $draft])
            ->filterTable('driver', 'oneshop-demo')->assertCanSeeTableRecords([$declined])->assertCanNotSeeTableRecords([$paid, $unknown, $draft])
            ->resetTableFilters()->assertCanSeeTableRecords([$paid, $declined, $unknown]);
        $this->get('/admin/payments/'.$paid->id)->assertOk()->assertSee('Testno potvrđeno')->assertSee('Naplata')
            ->assertSee(OrderResource::getUrl('view', ['record' => $order->id]), false);
        $this->get('/admin/payments/'.$declined->id)->assertOk()->assertSee('Testno odbijeno');
        $this->get('/admin/payments/'.$unknown->id)->assertOk()->assertSee('PROVIDER-STATE')->assertSee('Namjera plaćanja')->assertSee('other-processor');
        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
            ->assertSee('Testno potvrđeno')->assertSee('Testno odbijeno')->assertSee('Testna simulacija — bez novca')
            ->assertSee('PROVIDER-STATE')->assertSee('Namjera plaćanja');
        $this->assertSame($snapshots->all(), collect([$paid, $declined, $unknown])->map(fn (Transaction $payment) => $payment->fresh()->getRawOriginal())->all());
    }
}
