<?php

namespace App\Http\Controllers\Storefront;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lunar\Exceptions\Carts\CartException;

class CheckoutReviewRecovery
{
    public static function response(Request $request, ValidationException|CartException $exception, string $addressRoute): RedirectResponse
    {
        $request->session()->forget('oneshop.checkout_quote');
        if ($request->expectsJson()) {
            throw $exception;
        }
        $errors = $exception instanceof ValidationException ? $exception->errors() : ['cart' => [$exception->getMessage()]];
        $route = isset($errors['address']) || isset($errors['shipping']) ? $addressRoute : 'store.cart';

        // GET review cannot repair these conditions; redirecting back can loop.
        // Keep the cart and saved address so the customer can correct the cause.
        return to_route($route)->withErrors($errors);
    }
}
