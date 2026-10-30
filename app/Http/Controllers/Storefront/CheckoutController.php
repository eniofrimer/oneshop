<?php

namespace App\Http\Controllers\Storefront;

use App\Checkout\CheckoutDraft;
use App\Checkout\DevelopmentCheckoutService;
use App\Storefront\GuestCart;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Lunar\Models\CartAddress;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;

class CheckoutController
{
    public function index(Request $request, DevelopmentCheckoutService $checkout, GuestCart $carts): View|RedirectResponse
    {
        $checkout->guard();
        $cart = $carts->current($request->session());
        if (! $cart || $cart->lines->isEmpty()) {
            return to_route('store.cart');
        }
        $address = $cart->billingAddress;
        $address = $address instanceof CartAddress ? $address->only(['first_name', 'last_name', 'contact_email', 'contact_phone', 'line_one', 'line_two', 'city', 'postcode', 'country_id']) : [];
        $countries = Country::query()->whereNotNull('iso2')->orderBy('name')->get();

        return view('storefront.checkout-address', compact('address', 'countries'));
    }

    public function address(Request $request, DevelopmentCheckoutService $checkout): RedirectResponse
    {
        $checkout->address($request->session(), $request->all());

        return to_route('store.checkout.review');
    }

    public function shipping(Request $request, DevelopmentCheckoutService $checkout): RedirectResponse
    {
        $checkout->guard();
        $data = $request->validate(['shipping' => ['required', 'string', 'max:100']]);
        $checkout->selectShipping($request->session(), $data['shipping']);

        return to_route('store.checkout.review')->with('message', 'Dostava je ažurirana. Provjerite konačan iznos.');
    }

    public function review(Request $request, DevelopmentCheckoutService $checkout, CheckoutDraft $draft): View
    {
        $cart = $checkout->review($request->session());

        return view('storefront.checkout-review', ['cart' => $cart, 'options' => $draft->shippingQuotes($cart),
            'quote' => $request->session()->get('oneshop.checkout_quote')]);
    }

    public function place(Request $request, DevelopmentCheckoutService $checkout): RedirectResponse
    {
        $checkout->guard();
        $data = $request->validate(['quote' => ['required', 'string', 'size:64'], 'demo_acknowledged' => ['accepted']]);
        $record = $checkout->place($request->session(), $data['quote']);

        return to_route('store.checkout.payment', $record->id);
    }

    public function payment(Request $request, DevelopmentCheckoutService $checkout, string $checkoutToken): Response
    {
        $record = $checkout->owned($request->session(), $checkoutToken);
        $order = $record->order;
        abort_unless($order instanceof Order, 404);
        $order->loadMissing(['currency', 'lines']);
        foreach ($order->lines as $line) {
            abort_unless($line instanceof OrderLine, 404);
            $line->setRelation('order', $order);
        }

        return response()->view('storefront.checkout-payment', ['checkout' => $record, 'order' => $order])
            ->header('Cache-Control', 'private, no-store');
    }

    public function simulate(Request $request, DevelopmentCheckoutService $checkout, string $checkoutToken): RedirectResponse
    {
        $checkout->guard();
        $data = $request->validate(['outcome' => ['required', 'string', 'max:20']]);
        $record = $checkout->simulate($request->session(), $checkoutToken, $data['outcome']);

        return to_route('store.checkout.payment', $record->id);
    }
}
