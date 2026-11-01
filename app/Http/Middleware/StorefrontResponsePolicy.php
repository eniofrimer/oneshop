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
        if ($response->headers->get('Referrer-Policy') !== 'no-referrer') {
            $response->headers->set('Referrer-Policy', 'same-origin');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Enforce a separate policy so an existing CSP is never overwritten.
        // Mirror a stricter legacy DENY: modern CSP otherwise supersedes XFO.
        $denyFrames = strtoupper(trim($response->headers->get('X-Frame-Options', ''))) === 'DENY';
        $response->headers->set('X-Frame-Options', $denyFrames ? 'DENY' : 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', 'frame-ancestors '.($denyFrames ? "'none'" : "'self'"), false);

        return $response;
    }
}
