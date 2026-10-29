<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (blank(config('app.key'))) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
    }

    public function test_unfinished_customer_authentication_routes_are_not_registered(): void
    {
        foreach ([
            ['GET', '/auth/login'],
            ['POST', '/auth/login'],
            ['GET', '/auth/register'],
            ['POST', '/auth/register'],
            ['GET', '/auth/forgot-password'],
            ['POST', '/auth/forgot-password'],
            ['GET', '/auth/reset-password/test-token'],
            ['POST', '/auth/reset-password'],
            ['POST', '/auth/user/confirm-password'],
            ['GET', '/auth/user/confirmed-password-status'],
            ['POST', '/auth/logout'],
        ] as [$method, $path]) {
            $this->call($method, $path)->assertNotFound();
        }
    }

    public function test_fortify_cannot_create_a_password_only_administrator_session(): void
    {
        $administrator = User::factory()->create(['password' => 'administrator-password']);
        $administrator->forceFill(['is_admin' => true])->save();
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->post('/auth/login', [
            'email' => $administrator->email,
            'password' => 'administrator-password',
        ])->assertNotFound();

        $this->assertGuest('web');
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/horizon')->assertForbidden();
    }

    public function test_guest_cannot_access_horizon_even_in_local_environment(): void
    {
        config(['app.env' => 'local']);

        $this->get('/horizon')->assertForbidden();
    }

    public function test_customer_cannot_access_horizon_even_with_an_authenticator_secret(): void
    {
        config(['app.env' => 'local']);
        $customer = User::factory()->create();
        $customer->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->actingAs($customer)->get('/horizon')->assertForbidden();
    }

    public function test_administrator_must_enroll_mfa_before_accessing_horizon(): void
    {
        config(['app.env' => 'local']);
        $administrator = User::factory()->create();
        $administrator->forceFill(['is_admin' => true])->save();

        $this->actingAs($administrator)->get('/horizon')->assertForbidden();
    }

    public function test_authenticated_administrator_with_enrolled_mfa_can_access_horizon(): void
    {
        $administrator = User::factory()->create();
        $administrator->forceFill(['is_admin' => true])->save();
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->actingAs($administrator)->get('/horizon')->assertOk();
    }
}
