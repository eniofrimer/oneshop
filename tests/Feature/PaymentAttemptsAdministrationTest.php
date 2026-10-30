<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentAttempts\Pages\ListPaymentAttempts;
use App\Filament\Resources\PaymentAttempts\Pages\ViewPaymentAttempt;
use App\Filament\Resources\PaymentAttempts\PaymentAttemptResource;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Lunar\Models\Order;
use Tests\TestCase;

class PaymentAttemptsAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US']);
        $this->artisan('oneshop:initialize')->assertSuccessful();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function attempt(string $status = 'unknown', bool $placed = true): PaymentAttempt
    {
        $order = Order::factory()->create(['currency_code' => 'EUR', 'reference' => 'ATTEMPT-'.Str::random(8),
            'placed_at' => $placed ? now() : null]);

        return tap(new PaymentAttempt, fn (PaymentAttempt $attempt) => $attempt->forceFill([
            'id' => (string) Str::uuid(), 'order_id' => $order->id, 'provider' => 'stripe',
            'provider_account' => 'acct_adminFixture', 'environment' => 'test', 'amount_minor' => 2500,
            'currency_code' => 'EUR', 'idempotency_key' => (string) Str::uuid(), 'status' => $status,
            'review_reason' => $status === 'review' ? 'capture-currency-mismatch' : null,
            'creation_parameters' => ['secret' => 'DO_NOT_RENDER_CREATION_PARAMETERS'],
            'checkout_url' => 'https://checkout.stripe.com/c/pay/DO_NOT_RENDER_BEARER_URL',
        ])->save());
    }

    private function owner(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($owner);
    }

    public function test_read_only_attempts_show_review_and_actual_receipt_currency_without_exposing_secrets(): void
    {
        $this->owner();
        $attempt = $this->attempt('review');
        $unknown = $this->attempt();
        $draft = $this->attempt(placed: false);
        $receipt = new PaymentReceipt;
        $receipt->forceFill(['payment_attempt_id' => $attempt->id, 'provider' => 'stripe', 'provider_account' => $attempt->provider_account,
            'environment' => 'test', 'capture_reference' => 'ch_adminCapture', 'payment_reference' => 'pi_adminPayment',
            'amount_minor' => 2500, 'currency_code' => 'USD', 'allocation_status' => 'review', 'review_reason' => 'capture-currency-mismatch'])->save();
        $before = [$attempt->fresh()->getRawOriginal(), $receipt->fresh()->getRawOriginal()];

        $this->get('/admin/payment-attempts')->assertOk()->assertSee('Potrebna provjera')->assertSee('Nepoznat ishod')
            ->assertDontSee('DO_NOT_RENDER_CREATION_PARAMETERS')->assertDontSee('DO_NOT_RENDER_BEARER_URL');
        Livewire::test(ListPaymentAttempts::class)->assertCanSeeTableRecords([$attempt, $unknown])->assertCanNotSeeTableRecords([$draft])
            ->assertTableActionDoesNotExist(EditAction::class)->assertTableActionDoesNotExist(DeleteAction::class)
            ->filterTable('status', 'review')->assertCanSeeTableRecords([$attempt])->assertCanNotSeeTableRecords([$unknown, $draft]);
        $this->get('/admin/payment-attempts/'.$attempt->id)->assertOk()->assertSee('25,00 EUR')->assertSee('2500 USD (najmanje jedinice)')
            ->assertSee('ch_adminCapture')->assertSee('Valuta stvarne potvrde razlikuje se')
            ->assertDontSee('DO_NOT_RENDER_CREATION_PARAMETERS')->assertDontSee('DO_NOT_RENDER_BEARER_URL');
        Livewire::test(ViewPaymentAttempt::class, ['record' => $attempt->id])->assertSet('data', []);
        foreach (['not-a-uuid', '0', $draft->id, 'create', $attempt->id.'/edit'] as $key) {
            $this->get('/admin/payment-attempts/'.$key)->assertNotFound();
        }
        $this->assertFalse(PaymentAttemptResource::canEdit($attempt));
        $this->assertFalse(PaymentAttemptResource::canDelete($attempt));
        $this->assertSame($before, [$attempt->fresh()->getRawOriginal(), $receipt->fresh()->getRawOriginal()]);
    }

    public function test_customer_and_unenrolled_administrator_cannot_inspect_provider_attempts(): void
    {
        $attempt = $this->attempt();
        foreach ([User::factory()->create(), User::factory()->create(['is_admin' => true])] as $user) {
            $this->actingAs($user);
            $this->assertFalse(PaymentAttemptResource::canViewAny());
            foreach (['/admin/payment-attempts', '/admin/payment-attempts/'.$attempt->id] as $url) {
                $this->assertContains($this->get($url)->status(), [302, 403]);
            }
        }
    }
}
