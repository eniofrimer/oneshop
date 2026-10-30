<?php

namespace App\Http\Controllers\Storefront;

use App\Checkout\CheckoutDraft;
use App\Checkout\StripeSandboxCheckout;
use App\Models\PaymentAttempt;
use App\Payments\StripeSandboxPayments;
use App\Storefront\GuestCart;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Lunar\Models\CartAddress;
use Lunar\Models\Country;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;

class StripeSandboxCheckoutController
{
    public function index(Request $request, StripeSandboxCheckout $checkout, GuestCart $carts): View|RedirectResponse
    {
        $checkout->guard();
        $cart = $carts->current($request->session());
        if (! $cart || $cart->lines->isEmpty()) {
            return to_route('store.cart');
        }
        $address = $cart->billingAddress;
        $address = $address instanceof CartAddress ? $address->only(['first_name', 'last_name', 'contact_email', 'contact_phone', 'line_one', 'line_two', 'city', 'postcode', 'country_id']) : [];
        $countries = Country::query()->whereNotNull('iso2')->orderBy('name')->get();

        return view('storefront.checkout-address', ['address' => $address, 'countries' => $countries, 'checkoutMode' => 'stripe-sandbox']);
    }

    public function address(Request $request, StripeSandboxCheckout $checkout): RedirectResponse
    {
        $checkout->address($request->session(), $request->all());

        return to_route('store.stripe.review');
    }

    public function shipping(Request $request, StripeSandboxCheckout $checkout): RedirectResponse
    {
        $checkout->guard();
        $data = $request->validate(['shipping' => ['required', 'string', 'max:100']]);
        $checkout->selectShipping($request->session(), $data['shipping']);

        return to_route('store.stripe.review')->with('message', 'Dostava je ažurirana. Provjerite konačan iznos.');
    }

    public function review(Request $request, StripeSandboxCheckout $checkout, CheckoutDraft $draft): View
    {
        $cart = $checkout->review($request->session());

        return view('storefront.checkout-review', ['cart' => $cart, 'options' => $draft->shippingQuotes($cart),
            'quote' => $request->session()->get('oneshop.checkout_quote'), 'checkoutMode' => 'stripe-sandbox']);
    }

    public function place(Request $request, StripeSandboxCheckout $checkout): RedirectResponse
    {
        $checkout->guard();
        $data = $request->validate(['quote' => ['required', 'string', 'size:64'], 'stripe_test_acknowledged' => ['accepted']]);
        $attempt = $checkout->place($request->session(), $data['quote']);

        return to_route('store.stripe.return', $attempt->id);
    }

    public function result(Request $request, StripeSandboxCheckout $checkout, string $attemptToken): Response
    {
        // GET is read-only. Query parameters and a redirect never confirm money.
        $attempt = $checkout->owned($request->session(), $attemptToken);
        $order = $attempt->order;
        abort_unless($order instanceof Order, 404);
        $order->loadMissing(['currency', 'lines']);
        foreach ($order->lines as $line) {
            abort_unless($line instanceof OrderLine, 404);
            $line->setRelation('order', $order);
        }

        return response()->view('storefront.stripe-payment', ['attempt' => $attempt, 'order' => $order])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function launch(Request $request, StripeSandboxCheckout $checkout, StripeSandboxPayments $payments, string $attemptToken): RedirectResponse
    {
        $attempt = $payments->refresh($checkout->owned($request->session(), $attemptToken));
        if ($attempt->status === 'pending' && is_string($attempt->checkout_url)) {
            return redirect()->away($attempt->checkout_url);
        }

        return $this->backToAttempt($attempt);
    }

    public function refresh(Request $request, StripeSandboxCheckout $checkout, StripeSandboxPayments $payments, string $attemptToken): RedirectResponse
    {
        return $this->backToAttempt($payments->refresh($checkout->owned($request->session(), $attemptToken)));
    }

    public function cancel(Request $request, StripeSandboxCheckout $checkout, StripeSandboxPayments $payments, string $attemptToken): RedirectResponse
    {
        return $this->backToAttempt($payments->cancel($checkout->owned($request->session(), $attemptToken)));
    }

    private function backToAttempt(PaymentAttempt $attempt): RedirectResponse
    {
        return to_route('store.stripe.return', $attempt->id);
    }
}
