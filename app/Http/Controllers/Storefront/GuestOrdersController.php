<?php

namespace App\Http\Controllers\Storefront;

use App\Storefront\GuestOrders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GuestOrdersController
{
    public function __invoke(Request $request, GuestOrders $orders): Response
    {
        return response()->view('storefront.orders', ['orders' => $orders->recent($request->session())])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
