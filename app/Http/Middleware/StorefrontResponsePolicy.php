<?php

namespace App\Http\Middleware;

use App\Storefront\StoreSeo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StorefrontResponsePolicy
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $robots = 'noindex,nofollow';
        if (app(StoreSeo::class)->indexingEnabled() && $response->getStatusCode() === 200
            && $request->routeIs('store.home', 'store.product')
            && str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            $robots = $response->headers->get('X-Robots-Tag', $robots);
        }
        // Storefront HTML includes a session's cart count. Never allow a shared cache
        // to reuse it, and never retain addresses, checkout capabilities or admin pages.
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', $robots);
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'same-origin');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
