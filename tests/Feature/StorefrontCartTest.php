<?php

namespace Tests\Feature;

use App\Models\User;
use App\Storefront\GuestCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class StorefrontCartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.locale', 'hr');
        config()->set('app.faker_locale', 'en_US');
        app()->setLocale('hr');
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
    }

    public function test_demo_catalogue_renders_three_products_and_variable_sku_selection(): void
    {
        $this->get('/')->assertOk()->assertSee('Majica Osnova')->assertSee('Šalica Jutro')->assertSee('Torba Svaki dan')->assertSee('25,00 €');
        $shirt = ProductVariant::query()->where('sku', 'DEMO-SHIRT-1-1')->sole()->product;
        $this->get(route('store.product', $shirt))->assertOk()->assertSee('Maslinasta / M')->assertSee('Pijesak / L')->assertSee('Dodaj u košaricu');
        $this->assertSame(6, ProductVariant::query()->count());
    }

    public function test_guest_add_update_remove_uses_server_prices_and_never_associates_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($admin);
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 2, 'price' => 1, 'user_id' => $admin->id])->assertRedirect(route('store.cart'));
        $cart = Cart::query()->sole();
        $this->assertNull($cart->user_id);
        $this->assertNull($cart->customer_id);
        $this->assertSame($cart->id, session(GuestCart::SESSION_KEY));
        $this->assertSame(3000, $cart->recalculate()->total->value);
        $this->get(route('store.cart'))->assertOk()->assertSee('Šalica Jutro')->assertSee('30,00 €')->assertSee('/images/demo/cup.svg', false);
        $line = $cart->lines()->sole();
        $this->patch(route('store.cart.update', $line), ['quantity' => 3])->assertRedirect();
        $this->assertSame(3, $line->fresh()->quantity);
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertRedirect();
        $this->assertSame(4, $line->fresh()->quantity);
        $this->delete(route('store.cart.remove', $line))->assertRedirect();
        $this->assertSame(0, $cart->lines()->count());
        $this->get(route('store.cart'))->assertOk()->assertSee('Ovdje još nema favorita.');
    }

    public function test_unpublished_unscheduled_hidden_or_unpurchasable_products_cannot_be_added(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $product = $variant->product;
        $product->update(['status' => 'draft']);
        $this->get(route('store.product', $product))->assertNotFound();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertSessionHasErrors('cart');
        $product->update(['status' => 'published']);
        $channel = Channel::query()->where('default', true)->sole();
        $product->channels()->updateExistingPivot($channel->id, ['starts_at' => now()->addDay()]);
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertSessionHasErrors('cart');
        $product->channels()->updateExistingPivot($channel->id, ['starts_at' => now()->subMinute()]);
        $group = CustomerGroup::query()->where('default', true)->sole();
        foreach (['visible', 'purchasable', 'enabled'] as $flag) {
            $product->customerGroups()->updateExistingPivot($group->id, [$flag => false]);
            $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertSessionHasErrors('cart');
            $product->customerGroups()->updateExistingPivot($group->id, [$flag => true]);
        }
        $this->assertSame(0, Cart::query()->first()->lines()->count());
    }

    public function test_other_session_lines_and_posted_cart_ids_are_ignored(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertRedirect();
        $first = Cart::query()->sole();
        $line = $first->lines()->sole();
        $second = Cart::query()->create(['currency_id' => Currency::query()->sole()->id, 'channel_id' => Channel::query()->sole()->id]);
        $this->withSession([GuestCart::SESSION_KEY => $second->id]);
        $this->patch(route('store.cart.update', $line), ['quantity' => 5, 'cart_id' => $first->id])->assertNotFound();
        $this->delete(route('store.cart.remove', $line))->assertNotFound();
        $this->assertSame(1, $line->fresh()->quantity);
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 2, 'cart_id' => $first->id])->assertRedirect();
        $this->assertSame(2, $second->lines()->sole()->quantity);
        $this->assertSame(1, $line->fresh()->quantity);
    }

    public function test_invalid_quantities_stock_and_missing_price_are_reported_without_writes(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        foreach ([0, -1, 100, '1.5'] as $quantity) {
            $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => $quantity])->assertSessionHasErrors('quantity');
        }
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 21])->assertSessionHasErrors('cart');
        Price::query()->whereMorphedTo('priceable', $variant)->delete();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertSessionHasErrors('cart');
        $this->assertSame(0, Cart::query()->sole()->lines()->count());
    }

    public function test_stale_cart_can_remove_withdrawn_or_deleted_variants(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertRedirect();
        $line = Cart::query()->sole()->lines()->sole();
        $variant->delete();
        $this->get(route('store.cart'))->assertOk()->assertSee('Neki artikli više nisu dostupni');
        $this->delete(route('store.cart.remove', $line))->assertRedirect();
        $this->get(route('store.cart'))->assertOk()->assertSee('Ovdje još nema favorita.');
    }

    public function test_reseeding_preserves_merchant_changes_and_stock(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $variant->update(['stock' => 3]);
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(3, Product::query()->count());
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('oneshop:demo-catalog')->assertFailed();
        $this->assertSame(3, Product::query()->count());
    }

    public function test_invalid_array_input_can_redisplay_the_product_without_a_server_error(): void
    {
        $product = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole()->product;
        $url = route('store.product', $product);
        $this->followingRedirects()->from($url)->post(route('store.cart.add'), ['variant_id' => ['invalid'], 'quantity' => ['invalid']])
            ->assertOk()->assertSee('Provjerite unos')->assertDontSee('validation.integer');
        $this->assertSame(0, Cart::query()->count());
    }

    public function test_cart_shows_human_variant_choices_and_escapes_changed_option_names(): void
    {
        $variant = ProductVariant::query()->where('sku', 'DEMO-SHIRT-1-1')->sole();
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertRedirect();
        $this->get(route('store.cart'))->assertOk()->assertSee('Maslinasta / M')
            ->assertSee('href="'.route('store.product', $variant->product).'"', false);
        $value = $variant->values()->orderBy('product_option_id')->first();
        $value->update(['name' => ['hr' => '<b>Promijenjena boja</b>']]);
        $this->get(route('store.cart'))->assertOk()->assertSee('&lt;b&gt;Promijenjena boja&lt;/b&gt;', false)
            ->assertDontSee('<b>Promijenjena boja</b>', false);
        $variant->product->update(['status' => 'draft']);
        $this->get(route('store.cart'))->assertOk()->assertSee('Neki artikli više nisu dostupni')
            ->assertDontSee('href="'.route('store.product', $variant->product).'"', false);
    }

    public function test_cart_thumbnails_exclude_private_media_and_fall_back_while_conversions_are_pending(): void
    {
        Storage::fake('public');
        Storage::fake('private');
        config(['media-library.queue_conversions_by_default' => false]);
        $variant = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole();
        $product = $variant->product;
        $private = $product->addMedia(UploadedFile::fake()->image('PRIVATE-CART.jpg'))->toMediaCollection(config('lunar.media.collection'), 'private');
        $foreign = $product->addMedia(UploadedFile::fake()->image('FOREIGN-CART.jpg'))->toMediaCollection('other-gallery', 'public');
        $private->update(['order_column' => 0]);
        $foreign->update(['order_column' => 0]);
        $public = $product->addMedia(UploadedFile::fake()->image('public-cart.jpg'))->toMediaCollection(config('lunar.media.collection'), 'public')->refresh();
        $this->assertTrue($public->hasGeneratedConversion('small'));
        $this->post(route('store.cart.add'), ['variant_id' => $variant->id, 'quantity' => 1])->assertRedirect();
        $this->get(route('store.cart'))->assertOk()->assertSee($public->getUrl('small'), false)
            ->assertDontSee('PRIVATE-CART')->assertDontSee('FOREIGN-CART')->assertSee('15,00 €');
        $public->forceFill(['generated_conversions' => []])->save();
        $this->get(route('store.cart'))->assertOk()->assertSee('src="'.$public->getUrl().'"', false)
            ->assertDontSee($public->getUrl('small'), false);
        $product->update(['status' => 'draft']);
        $this->get(route('store.cart'))->assertOk()->assertSee('Neki artikli više nisu dostupni')
            ->assertDontSee('class="cart-thumbnail"', false);
    }
}
