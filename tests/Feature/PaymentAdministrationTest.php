<?php

namespace Tests\Feature;

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
}
