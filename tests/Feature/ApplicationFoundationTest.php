<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplicationFoundationTest extends TestCase
{
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
}
