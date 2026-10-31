<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApplicationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_storefront_is_available_without_exposing_a_live_checkout(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Razvojna instanca')
            ->assertSee('noindex,nofollow', false);
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_default_referrer_policy_never_weakens_an_explicit_no_referrer(): void
    {
        Route::get('/test-referrer-weak', fn () => response('public')->header('Referrer-Policy', 'unsafe-url'));
        Route::get('/test-referrer-private', fn () => response('private')->header('Referrer-Policy', 'no-referrer'));
        $this->get('/test-referrer-weak')->assertOk()->assertHeader('Referrer-Policy', 'same-origin');
        $this->get('/test-referrer-private')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_html_errors_are_readable_without_assets_and_json_keeps_its_format(): void
    {
        config(['app.debug' => false]);
        $this->get('/missing-page')->assertNotFound()->assertSee('Ova stranica nije dostupna.')
            ->assertSee('lang="hr"', false)->assertDontSee('/build/assets/', false);
        $this->getJson('/missing-page')->assertNotFound()->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message'])->assertDontSee('<!DOCTYPE html>', false);
        Route::get('/test-only-failure', fn () => throw new \RuntimeException('PRIVATE_ERROR_DETAILS'));
        $this->get('/test-only-failure')->assertStatus(500)->assertSee('Nešto je pošlo po zlu.')
            ->assertDontSee('PRIVATE_ERROR_DETAILS')->assertDontSee('/build/assets/', false);
    }
}
