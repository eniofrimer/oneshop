<?php

namespace Tests\Feature;

use App\Catalog\VariantEditor;
use App\Checkout\DevelopmentCheckoutService;
use App\Filament\Widgets\ShopOverview;
use App\Inventory\StockAvailability;
use App\Inventory\StockReservations;
use App\Models\DevelopmentCheckout;
use App\Models\StockReservation;
use App\Models\User;
use App\Storefront\GuestCart;
use App\Storefront\StoreCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Lunar\Models\Cart;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\Price;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;
use Tests\TestCase;

class StockReservationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.faker_locale' => 'en_US', 'oneshop.demo_checkout' => true]);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->artisan('oneshop:demo-shipping')->assertSuccessful();
    }

    private function variant(): ProductVariant
    {
        return ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
    }

    private function place(int $quantity = 2): DevelopmentCheckout
    {
        $this->post(route('store.cart.add'), ['variant_id' => $this->variant()->id, 'quantity' => $quantity])->assertRedirect();
        $this->post(route('store.checkout.address'), [
            'first_name' => 'Ana', 'last_name' => 'Rezervacija', 'contact_email' => 'inventory@example.test',
            'contact_phone' => '+385 91 000 0000', 'line_one' => 'Testna ulica 1',
            'city' => 'Zagreb', 'postcode' => '10000', 'country_id' => Country::query()->where('iso2', 'HR')->sole()->id,
        ])->assertRedirect();
        $this->get(route('store.checkout.review'))->assertOk();
        $this->post(route('store.checkout.place'), ['quote' => session('oneshop.checkout_quote'), 'demo_acknowledged' => 1])->assertRedirect();

        return DevelopmentCheckout::query()->where('cart_id', session(GuestCart::SESSION_KEY))->sole();
    }

    private function becomeOwner(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['is_admin' => true, 'is_super_admin' => true])->save();
        $this->actingAs($owner);
    }

    public function test_placement_holds_stock_and_repeated_submission_does_not_hold_twice(): void
    {
        $checkout = $this->place();
        $this->assertTrue($checkout->uses_stock_reservations);
        $this->assertSame(20, $this->variant()->stock);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->assertSame(18, StockAvailability::available($this->variant()));
        $this->post(route('store.checkout.place'), ['quote' => session('oneshop.checkout_quote'), 'demo_acknowledged' => 1])->assertRedirect();
        $this->assertSame(1, StockReservation::query()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_success_converts_the_hold_to_one_stock_consumption(): void
    {
        $checkout = $this->place();
        foreach (['paid', 'paid', 'cancelled'] as $outcome) {
            $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => $outcome])->assertRedirect();
        }
        $row = StockReservation::query()->sole();
        $this->assertSame(StockReservation::CONSUMED, $row->state);
        $this->assertNotNull($row->consumed_at);
        $this->assertNull($row->released_at);
        $this->assertSame(18, $this->variant()->stock);
        $this->assertSame(0, StockAvailability::held($this->variant()->id));
        $this->assertSame(18, StockAvailability::available($this->variant()));
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_decline_keeps_the_hold_and_confirmed_demo_cancellation_releases_it(): void
    {
        $checkout = $this->place();
        $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => 'declined'])->assertRedirect();
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => 'cancelled'])->assertRedirect();
        $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => 'cancelled'])->assertRedirect();
        $row = StockReservation::query()->sole();
        $this->assertSame(StockReservation::RELEASED, $row->state);
        $this->assertSame('demo-cancelled', $row->release_reason);
        $this->assertNotNull($row->released_at);
        $this->assertSame(20, $this->variant()->stock);
        $this->assertSame(20, StockAvailability::available($this->variant()));
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, Transaction::query()->where('success', false)->count());
    }

    public function test_due_simulators_release_once_but_production_never_runs_the_expiry(): void
    {
        $checkout = $this->place();
        $this->travel(31)->minutes();
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('oneshop:expire-demo-checkouts')->assertSuccessful();
        $this->assertSame('pending', $checkout->fresh()->status);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->app->detectEnvironment(fn () => 'testing');
        $this->artisan('oneshop:expire-demo-checkouts')->expectsOutputToContain('Expired development checkouts: 1')->assertSuccessful();
        $this->artisan('oneshop:expire-demo-checkouts')->expectsOutputToContain('Expired development checkouts: 0')->assertSuccessful();
        $this->assertSame('expired', $checkout->fresh()->status);
        $this->assertSame('demo-expired', StockReservation::query()->sole()->release_reason);
        $this->assertSame(20, StockAvailability::available($this->variant()));
        $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => 'paid'])->assertRedirect();
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_abandoned_generic_hold_has_no_automatic_time_based_release(): void
    {
        $checkout = $this->place();
        // Simulates a future unresolved external attempt: only this simulator's
        // own key is eligible for its safe local expiry.
        $otherKey = (string) Str::uuid();
        $order = $checkout->order->replicate();
        $order->forceFill(['reference' => 'GENERIC-HOLD', 'cart_id' => null])->save();
        foreach ($checkout->order->productLines()->get() as $line) {
            $line->replicate()->forceFill(['order_id' => $order->id])->save();
        }
        app(StockReservations::class)->reserve($otherKey, $order, StockReservations::quantities($order));
        $this->travel(31)->minutes();
        $this->artisan('oneshop:expire-demo-checkouts')->assertSuccessful();
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->assertSame(StockReservation::HELD, StockReservation::query()->where('reservation_key', $otherKey)->sole()->state);
    }

    public function test_all_held_inventory_is_unavailable_in_catalog_selection_search_and_cart(): void
    {
        $this->variant()->update(['stock' => 2]);
        $checkout = $this->place();
        $variant = $this->variant();
        $presented = app(StoreCatalog::class)->presentation($variant->product);
        $this->assertFalse($presented['variants']->sole()['available']);
        $this->becomeOwner();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ShopOverview::class)->assertSee('Praćenih SKU-ova bez raspoložive zalihe: 1');
        $this->get('/?q=DEMO-CUP&available=1')->assertOk()->assertDontSee('Šalica Jutro');
        $this->withSession([GuestCart::SESSION_KEY => null]);
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertSessionHasErrors('cart');
        $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => 'cancelled'])->assertRedirect();
        $this->get('/?q=DEMO-CUP&available=1')->assertOk()->assertSee('Šalica Jutro');
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertRedirect();
    }

    public function test_cart_quantity_cannot_use_stock_held_by_another_order(): void
    {
        $this->place(18);
        $this->withSession([GuestCart::SESSION_KEY => null]);
        $this->post(route('store.cart.add'), ['variant_id' => $this->variant()->id, 'quantity' => 3])->assertSessionHasErrors('cart');
        $this->post(route('store.cart.add'), ['variant_id' => $this->variant()->id, 'quantity' => 2])->assertRedirect();
        $line = Cart::query()->findOrFail(session(GuestCart::SESSION_KEY))->lines()->sole();
        $this->patch(route('store.cart.update', $line->id), ['quantity' => 3])->assertSessionHasErrors('cart');
        $this->assertSame(2, $line->fresh()->quantity);
        $this->assertSame(20, $this->variant()->stock);
    }

    public function test_admin_cannot_rewrite_held_stock_or_tracking_and_can_add_stock(): void
    {
        $this->place();
        $this->becomeOwner();
        $variant = $this->variant();
        $priceBefore = Price::query()->whereMorphedTo('priceable', $variant)->sole()->getRawOriginal('price');
        foreach ([['stock' => 1], ['purchasable' => 'always'], ['shippable' => false]] as $change) {
            try {
                app(VariantEditor::class)->save($variant->product, [...VariantEditor::formData($variant), ...$change, 'price_eur' => '99.00'], $variant);
                $this->fail('The held inventory change must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('stock', $exception->errors());
            }
            $this->assertSame(20, $variant->fresh()->stock);
            $this->assertSame($priceBefore, Price::query()->whereMorphedTo('priceable', $variant)->sole()->getRawOriginal('price'));
        }
        app(VariantEditor::class)->save($variant->product, [...VariantEditor::formData($variant), 'stock' => 25], $variant);
        $this->assertSame(23, StockAvailability::available($this->variant()));
        $this->get('/admin/products/'.$variant->product_id.'/edit')->assertOk();
    }

    public function test_archive_refuses_active_holds_then_preserves_released_history(): void
    {
        $checkout = $this->place();
        $this->becomeOwner();
        $variant = $this->variant();
        $variant->product->update(['status' => 'draft']);
        try {
            app(VariantEditor::class)->setArchived($variant->product, $variant, true);
            $this->fail('An active hold must prevent archiving.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sku', $exception->errors());
        }
        $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => 'cancelled'])->assertRedirect();
        app(VariantEditor::class)->setArchived($variant->product, $variant, true);
        $this->assertTrue($variant->fresh()->trashed());
        $this->assertSame(StockReservation::RELEASED, StockReservation::query()->sole()->state);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_reservation_identity_and_quantities_cannot_be_reused_for_another_snapshot(): void
    {
        $checkout = $this->place();
        $service = app(StockReservations::class);
        $service->reserve($checkout->id, $checkout->order, [$this->variant()->id => 2]);
        try {
            $service->reserve($checkout->id, $checkout->order, [$this->variant()->id => 3]);
            $this->fail('A different payload must not reuse the reservation key.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('snapshot', $exception->getMessage());
        }
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->assertSame(1, StockReservation::query()->count());
    }

    public function test_a_storage_failure_rolls_back_hold_consumption_with_stock_and_payment(): void
    {
        $checkout = $this->place();
        $fail = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
            if ($fail && str_starts_with(strtolower($sql), 'insert into') && str_contains($sql, (new Transaction)->getTable())) {
                $fail = false;
                throw new \RuntimeException('Injected reservation capture failure');
            }
        });
        try {
            app(DevelopmentCheckoutService::class)->simulate(session()->driver(), $checkout->id, 'paid');
            $this->fail('The storage failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected reservation capture failure', $exception->getMessage());
        }
        $this->assertSame(StockReservation::HELD, StockReservation::query()->sole()->state);
        $this->assertSame(20, $this->variant()->stock);
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
        $this->assertSame('pending', $checkout->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
        $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => 'paid'])->assertRedirect();
        $this->assertSame(18, $this->variant()->stock);
        $this->assertSame(StockReservation::CONSUMED, StockReservation::query()->sole()->state);
    }

    public function test_untracked_variants_do_not_create_holds_or_change_physical_stock(): void
    {
        $this->variant()->update(['purchasable' => 'always', 'stock' => 0]);
        $checkout = $this->place();
        $this->assertSame(0, StockReservation::query()->count());
        $this->post(route('store.checkout.simulate', $checkout->id), ['outcome' => 'paid'])->assertRedirect();
        $this->assertSame(0, $this->variant()->stock);
        $this->assertSame('paid', $checkout->fresh()->status);
    }

    public function test_legacy_pending_payment_cannot_consume_a_new_orders_hold(): void
    {
        $legacy = $this->place();
        // Reconstruct the exact pre-migration shape on the disposable database.
        StockReservation::query()->where('reservation_key', $legacy->id)->delete();
        DB::table('development_checkouts')->where('id', $legacy->id)->update(['uses_stock_reservations' => false]);
        foreach ($legacy->order->productLines()->get() as $line) {
            $line->update(['meta' => ['oneshop_demo_track_stock' => true]]);
        }
        $this->withSession([GuestCart::SESSION_KEY => null]);
        $new = $this->place(19);
        $this->post(route('store.checkout.simulate', $legacy->id), ['outcome' => 'paid'])->assertSessionHasErrors('payment');
        $this->assertSame('pending', $legacy->fresh()->status);
        $this->assertSame(20, $this->variant()->stock);
        $this->assertSame(19, StockAvailability::held($this->variant()->id));
        $this->post(route('store.checkout.simulate', $new->id), ['outcome' => 'cancelled'])->assertRedirect();
        $this->post(route('store.checkout.simulate', $legacy->id), ['outcome' => 'paid'])->assertRedirect();
        $this->assertSame(18, $this->variant()->stock);
        $this->assertSame(0, StockAvailability::held($this->variant()->id));
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_an_order_cannot_acquire_another_active_reservation_key(): void
    {
        $checkout = $this->place();
        try {
            app(StockReservations::class)->reserve((string) Str::uuid(), $checkout->order, StockReservations::quantities($checkout->order));
            $this->fail('An unresolved order must not create another hold.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }
        $this->assertSame(2, StockAvailability::held($this->variant()->id));
    }
}
