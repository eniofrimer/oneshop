<?php

namespace Tests\Feature;

use App\Http\Middleware\StorefrontResponsePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Lunar\Models\ProductVariant;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class FramingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_private_redirect_and_error_responses_restrict_embedding_to_their_own_origin(): void
    {
        $this->artisan('oneshop:demo-catalog')->assertSuccessful();
        $product = ProductVariant::query()->where('sku', 'DEMO-CUP')->sole()->product;
        foreach (['/', '/proizvodi/'.$product->id, '/kosarica', '/admin/login', '/admin/products', '/?q=x', '/missing-page', '/robots.txt'] as $path) {
            $this->get($path)->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        }
    }

    public function test_frame_protection_preserves_each_existing_csp_policy(): void
    {
        $policies = ["script-src 'self'", "frame-ancestors 'none'; object-src 'none'"];
        $original = new Response('example');
        $original->headers->set('Content-Security-Policy', $policies);
        $response = app(StorefrontResponsePolicy::class)->handle(Request::create('/'), fn () => $original);
        $this->assertSame([...$policies, "frame-ancestors 'self'"], $response->headers->all('Content-Security-Policy'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function test_a_stricter_legacy_deny_remains_denied_in_modern_browsers(): void
    {
        $original = new Response('example', headers: ['X-Frame-Options' => 'DENY', 'Content-Security-Policy' => "script-src 'self'"]);
        $response = app(StorefrontResponsePolicy::class)->handle(Request::create('/'), fn () => $original);
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame(["script-src 'self'", "frame-ancestors 'none'"], $response->headers->all('Content-Security-Policy'));
    }
}
