<?php

namespace Tests\Feature;

use App\Catalog\CatalogTranslations;
use App\Checkout\DevelopmentCheckoutService;
use App\Models\DevelopmentCheckout;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Storefront\GuestCart;
use Dom\HTMLDocument;
use Dom\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Models\Cart;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\Price;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;
use Tests\TestCase;

class DevelopmentCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US', 'oneshop.demo_checkout' => true, 'lunar.pricing.stored_inclusive_of_tax' => false]);
        app()->setLocale('hr');
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
    }

    private function addressData(): array
    {
        return ['first_name' => 'Ana', 'last_name' => 'Demo', 'contact_email' => 'ana@example.test', 'contact_phone' => '+385 91 000 0000',
            'line_one' => 'Testna ulica 1', 'line_two' => '', 'city' => 'Zagreb', 'postcode' => '10000', 'country_id' => Country::query()->where('iso2', 'HR')->sole()->id];
    }

    private function prepare(int $quantity = 2): string
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => $quantity])->assertRedirect(route('store.cart'));
        $this->get(route('store.checkout'))->assertOk()->assertSee('Kamo šaljemo');
        $this->post(route('store.checkout.address'), $this->addressData())->assertRedirect(route('store.checkout.review'));
        $this->get(route('store.checkout.review'))->assertOk()->assertSee('Konačan iznos')->assertSee('DEMO — Standardna dostava');

        return session('oneshop.checkout_quote');
    }

    private function place(int $quantity = 2): DevelopmentCheckout
    {
        $quote = $this->prepare($quantity);
        $this->post(route('store.checkout.place'), ['quote' => $quote, 'demo_acknowledged' => 1, 'total' => 1, 'currency' => 'USD'])->assertRedirect();

        return DevelopmentCheckout::query()->where('cart_id', session(GuestCart::SESSION_KEY))->sole();
    }

    public function test_cart_additions_do_not_consume_the_order_submission_rate_limit(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        for ($attempt = 0; $attempt < 15; $attempt++) {
            $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])
                ->assertRedirect(route('store.cart'));
        }
        $this->post(route('store.checkout.address'), $this->addressData())
            ->assertRedirect(route('store.checkout.review'));
        $this->get(route('store.checkout.review'))->assertOk();
        $this->post(route('store.checkout.place'), ['quote' => session('oneshop.checkout_quote'), 'demo_acknowledged' => 1])
            ->assertRedirect();

        $this->assertSame(1, DevelopmentCheckout::query()->count());
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(20, $variant->fresh()->stock);
    }

    public function test_order_submission_keeps_its_own_rate_limit(): void
    {
        for ($attempt = 0; $attempt < 15; $attempt++) {
            $this->postJson(route('store.checkout.place'), [])->assertUnprocessable();
        }
        $this->postJson(route('store.checkout.place'), [])->assertTooManyRequests()->assertHeader('Retry-After');
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertRedirect(route('store.cart'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_address_and_simulator_limits_are_separate_from_each_other_and_order_submission(): void
    {
        foreach ([route('store.checkout.address'), route('store.checkout.simulate', '11111111-1111-4111-8111-111111111111')] as $url) {
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $this->postJson($url, [])->assertUnprocessable();
            }
            $this->postJson($url, [])->assertTooManyRequests()->assertHeader('Retry-After');
        }
        $this->postJson(route('store.checkout.place'), [])->assertUnprocessable();
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertRedirect(route('store.cart'));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_full_guest_checkout_freezes_eur_addresses_and_completes_once_without_real_money(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($user);
        $checkout = $this->place();
        $this->assertSame(3500, $checkout->amount_minor);
        $order = $checkout->order;
        $this->assertNull($order->user_id);
        $this->assertNull($order->customer_id);
        $this->assertNotNull($order->placed_at);
        $this->assertSame('EUR', $order->currency_code);
        $this->assertSame('Testna ulica 1', $order->billingAddress->line_one);
        $this->assertCount(2, $order->addresses);
        $this->get(route('store.checkout.payment', $checkout->id).'?paid=1&amount=1')->assertOk()->assertSee('Još jedan testni korak.');
        $this->assertSame(0, Transaction::query()->count());
        $quote = session('oneshop.checkout_quote');
        $this->post(route('store.checkout.place'), ['quote' => $quote, 'demo_acknowledged' => 1])->assertRedirect(route('store.checkout.payment', $checkout->id));
        $this->assertSame(1, Order::query()->count());
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->assertSame(20, $variant->stock);
        $url = route('store.checkout.simulate', $checkout->id);
        foreach (['paid', 'paid', 'declined', 'cancelled'] as $outcome) {
            $this->post($url, ['outcome' => $outcome, 'amount' => 1])->assertRedirect();
        }
        $this->assertSame('paid', $checkout->fresh()->status);
        $this->assertSame(18, $variant->fresh()->stock);
        $this->assertSame('payment-received', $order->fresh()->status);
        $transaction = Transaction::query()->sole();
        $this->assertSame('oneshop-demo', $transaction->driver);
        $this->assertSame(3500, (int) $transaction->getRawOriginal('amount'));
        $this->get(route('store.checkout.payment', $checkout->id))->assertOk()->assertSee('Testna kupnja je uspjela');
        $this->get('/admin/orders/'.$order->id)->assertOk()->assertSee($order->reference);
        $this->get(route('store.cart'))->assertOk()->assertSee('Ovdje još nema favorita');
    }

    public function test_changed_price_or_shipping_after_review_requires_fresh_review(): void
    {
        $quote = $this->prepare();
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        Price::query()->whereMorphedTo('priceable', $variant)->update(['price' => 1600]);
        $this->post(route('store.checkout.place'), ['quote' => $quote, 'demo_acknowledged' => 1])->assertSessionHasErrors('quote');
        $this->assertSame(0, Order::query()->count());
        $this->get(route('store.checkout.review'))->assertOk()->assertSee('45,00 €');
        $quote = session('oneshop.checkout_quote');
        ShippingMethod::query()->sole()->forceFill(['price_minor' => 800])->save();
        $this->post(route('store.checkout.place'), ['quote' => $quote, 'demo_acknowledged' => 1])->assertSessionHasErrors('quote');
        $this->assertSame(0, Order::query()->count());
        ShippingMethod::query()->sole()->forceFill(['enabled' => false])->save();
        $this->post(route('store.checkout.place'), ['quote' => $quote, 'demo_acknowledged' => 1])->assertSessionHasErrors('shipping');
    }

    public function test_decline_is_repeatable_then_success_uses_same_order_and_stock_once(): void
    {
        $checkout = $this->place();
        $url = route('store.checkout.simulate', $checkout->id);
        $this->post($url, ['outcome' => 'declined'])->assertRedirect();
        $this->post($url, ['outcome' => 'declined'])->assertRedirect();
        $this->assertSame('declined', $checkout->fresh()->status);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(20, ProductVariant::query()->where('sku', 'DEMO-CUP')->sole()->stock);
        $this->get(route('store.checkout.payment', $checkout->id))->assertOk()->assertSee('Plaćanje je testno odbijeno.');
        $this->post($url, ['outcome' => 'paid'])->assertRedirect();
        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(18, ProductVariant::query()->where('sku', 'DEMO-CUP')->sole()->stock);
    }

    public function test_foreign_sessions_cannot_read_or_settle_even_with_known_checkout_id(): void
    {
        $checkout = $this->place();
        $this->withSession([DevelopmentCheckoutService::OWNED_KEY => [], GuestCart::SESSION_KEY => null]);
        $this->get(route('store.checkout.payment', $checkout->id))->assertNotFound()->assertDontSee('ana@example.test')
            ->assertDontSee('DEMO-CUP')->assertDontSee('Šalica Jutro');
        $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => 'paid', 'order_id' => $checkout->order_id])->assertNotFound();
        $this->assertSame('pending', $checkout->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_cancellation_and_expiry_are_terminal_and_do_not_change_stock(): void
    {
        $first = $this->place();
        $this->post(route('store.checkout.simulate', $first->id), ['outcome' => 'cancelled'])->assertRedirect();
        $this->post(route('store.checkout.simulate', $first->id), ['outcome' => 'paid'])->assertRedirect();
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('demo-cancelled', $first->order->fresh()->status);
        $quote = $this->prepare();
        $this->post(route('store.checkout.place'), ['quote' => $quote, 'demo_acknowledged' => 1])->assertRedirect();
        $second = DevelopmentCheckout::query()->whereKeyNot($first->id)->sole();
        $this->travel(31)->minutes();
        $this->get(route('store.checkout.payment', $second->id))->assertOk()->assertSee('Vrijeme za test je isteklo.');
        $this->post(route('store.checkout.simulate', $second->id), ['outcome' => 'paid'])->assertRedirect();
        $this->assertSame('expired', $second->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(20, ProductVariant::query()->where('sku', 'DEMO-CUP')->sole()->stock);
    }

    public function test_simulator_never_sells_the_same_last_stock_twice(): void
    {
        ProductVariant::query()->where('sku', 'DEMO-CUP')->update(['stock' => 3]);
        $first = $this->place();
        $quote = $this->prepare();
        $this->post(route('store.checkout.place'), ['quote' => $quote, 'demo_acknowledged' => 1])->assertRedirect();
        $second = DevelopmentCheckout::query()->whereKeyNot($first->id)->sole();
        $this->post(route('store.checkout.simulate', $first->id), ['outcome' => 'paid'])->assertRedirect();
        $this->post(route('store.checkout.simulate', $second->id), ['outcome' => 'paid'])->assertSessionHasErrors('payment');
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, ProductVariant::query()->where('sku', 'DEMO-CUP')->sole()->stock);
    }

    public function test_invalid_address_shipping_acknowledgement_and_quotes_do_not_create_orders(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertRedirect();
        $this->post(route('store.checkout.address'), [...$this->addressData(), 'contact_email' => 'invalid'])->assertSessionHasErrors('contact_email');
        $this->assertSame(0, Cart::query()->sole()->addresses()->count());
        $this->post(route('store.checkout.address'), $this->addressData())->assertRedirect();
        $this->post(route('store.checkout.shipping'), ['shipping' => 'fake-free-shipping'])->assertSessionHasErrors('shipping');
        $this->get(route('store.checkout.review'))->assertOk();
        $quote = session('oneshop.checkout_quote');
        $this->post(route('store.checkout.place'), ['quote' => $quote])->assertSessionHasErrors('demo_acknowledged');
        $this->post(route('store.checkout.place'), ['quote' => str_repeat('a', 64), 'demo_acknowledged' => 1])->assertSessionHasErrors('quote');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_production_and_disabled_simulator_reject_all_entry_points(): void
    {
        foreach ([false, true] as $production) {
            config()->set('oneshop.demo_checkout', $production);
            if ($production) {
                $this->app->detectEnvironment(fn () => 'production');
            }
            $this->assertFalse(DevelopmentCheckoutService::enabled());
            $this->get(route('store.checkout'))->assertNotFound();
            $this->withSession(['_token' => 'csrf-fixture']);
            $this->post(route('store.checkout.address'), [...$this->addressData(), '_token' => 'csrf-fixture'])->assertNotFound();
            $this->post(route('store.checkout.place'), ['quote' => str_repeat('a', 64), 'demo_acknowledged' => 1, '_token' => 'csrf-fixture'])->assertNotFound();
        }
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_invalid_array_fields_redisplay_checkout_without_losing_valid_inputs(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertRedirect();
        $this->followingRedirects()->from(route('store.checkout'))->post(route('store.checkout.address'), [
            ...$this->addressData(), 'first_name' => ['invalid'], 'country_id' => ['invalid'],
        ])->assertOk()->assertSee('Provjerite unos')
            ->assertSee('value="ana@example.test"', false)->assertSee('id="first_name-error"', false)
            ->assertSee('aria-invalid="true"', false)->assertDontSee('validation.string');
        $this->assertSame(0, Cart::query()->sole()->addresses()->count());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_review_shows_the_chosen_variant_and_zero_address_detail_before_order_placement(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-SHIRT-2-2')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertRedirect();
        $this->post(route('store.checkout.address'), [...$this->addressData(), 'line_two' => '0'])->assertRedirect();
        $response = $this->get(route('store.checkout.review'))->assertOk()->assertSee('Pijesak / L')
            ->assertSee('DEMO-SHIRT-2-2')->assertSee('30,00 €');
        $address = HTMLDocument::createFromString($response->getContent())->querySelector('address');
        $this->assertNotNull($address);
        $this->assertContains('0', array_map(fn (Node $part): string => trim($part->textContent), iterator_to_array($address->childNodes)));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(20, $variant->fresh()->stock);
    }

    public function test_tax_inclusive_price_storage_does_not_add_tax_twice_in_cart_or_checkout(): void
    {
        config()->set('lunar.pricing.stored_inclusive_of_tax', true);
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        Price::query()->whereMorphedTo('priceable', $variant)->update(['price' => 1500]);
        ShippingMethod::query()->sole()->forceFill(['price_minor' => 500])->save();
        $quote = $this->prepare();
        $this->get(route('store.cart'))->assertOk()->assertSee('30,00 €')->assertDontSee('36,00 €');
        $this->post(route('store.checkout.place'), ['quote' => $quote, 'demo_acknowledged' => 1])->assertRedirect();
        $checkout = DevelopmentCheckout::query()->sole();
        $this->assertSame(3500, $checkout->amount_minor);
        $this->assertSame(700, (int) $checkout->order->getRawOriginal('tax_total'));
        $this->assertSame(500, (int) $checkout->order->getRawOriginal('shipping_total'));
    }

    public function test_catalog_changes_after_placement_do_not_reprice_test_payment(): void
    {
        $checkout = $this->place();
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        Price::query()->whereMorphedTo('priceable', $variant)->update(['price' => 9999]);
        $variant->update(['sku' => 'CHANGED-CUP']);
        $variant->product->update(['attribute_data' => CatalogTranslations::merge($variant->product, ['hr' => ['name' => 'Promijenjeni proizvod', 'description' => '']])]);
        ShippingMethod::query()->sole()->forceFill(['price_minor' => 9000])->save();
        $response = $this->get(route('store.checkout.payment', $checkout->id))->assertOk()
            ->assertSee('Šalica Jutro')->assertSee('DEMO-CUP')->assertSee('35,00 EUR')
            ->assertDontSee('CHANGED-CUP')->assertDontSee('Promijenjeni proizvod')->assertDontSee('112,50 EUR');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => 'paid'])->assertRedirect();
        $this->assertSame(3500, (int) Transaction::query()->sole()->getRawOriginal('amount'));
        $this->assertSame(3500, (int) $checkout->order->fresh()->getRawOriginal('total'));
    }

    public function test_confirmation_escapes_snapshot_text_and_does_not_render_private_metadata(): void
    {
        $checkout = $this->place();
        $order = $checkout->order;
        $order->productLines()->sole()->update(['description' => '<script>snapshot</script>', 'option' => '<b>option</b>', 'meta' => ['PRIVATE_LINE_MARKER' => 'not rendered']]);
        $order->update(['notes' => 'PRIVATE_ORDER_NOTE', 'meta' => ['oneshop_mode' => 'demo', 'PRIVATE_ORDER_MARKER' => 'not rendered']]);
        $this->get(route('store.checkout.payment', $checkout->id))->assertOk()
            ->assertSee('&lt;script&gt;snapshot&lt;/script&gt;', false)->assertSee('&lt;b&gt;option&lt;/b&gt;', false)
            ->assertDontSee('<script>snapshot</script>', false)->assertDontSee('<b>option</b>', false)
            ->assertDontSee('PRIVATE_LINE_MARKER')->assertDontSee('PRIVATE_ORDER_NOTE')->assertDontSee('PRIVATE_ORDER_MARKER')
            ->assertDontSee('oneshop-shipping:');
        $this->assertSame(0, Transaction::query()->count());
    }
}
