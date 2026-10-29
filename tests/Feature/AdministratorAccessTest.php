<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministratorAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (blank(config('app.key'))) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
    }

    public function test_customer_cannot_access_administration_even_in_local_environment(): void
    {
        config(['app.env' => 'local']);

        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_guest_is_directed_to_administrator_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_new_administrator_must_enroll_mfa_before_accessing_dashboard(): void
    {
        $administrator = User::factory()->create();
        $administrator->forceFill(['is_admin' => true])->save();

        $setupUrl = Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl();

        $this->actingAs($administrator)
            ->get('/admin')
            ->assertRedirect($setupUrl);

        $this->get($setupUrl)->assertOk();
    }

    public function test_customer_cannot_access_administrator_mfa_enrollment(): void
    {
        $setupUrl = Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl();

        $this->actingAs(User::factory()->create())
            ->get($setupUrl)
            ->assertForbidden();
    }

    public function test_administrator_with_enrolled_mfa_can_access_dashboard(): void
    {
        $administrator = User::factory()->create();
        $administrator->forceFill(['is_admin' => true])->save();
        $administrator->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->actingAs($administrator)->get('/admin')->assertOk();
    }

    public function test_customer_mass_assignment_cannot_grant_admin_access_or_set_mfa_secrets(): void
    {
        $customer = User::query()->create([
            'name' => 'Customer',
            'email' => 'customer@example.test',
            'password' => 'customer-password',
            'is_admin' => true,
            'app_authentication_secret' => 'attacker-controlled-secret',
            'app_authentication_recovery_codes' => ['attacker-controlled-code'],
        ])->fresh();

        $this->assertFalse($customer->is_admin);
        $this->assertNull($customer->getAppAuthenticationSecret());
        $this->assertNull($customer->getAppAuthenticationRecoveryCodes());

        $this->actingAs($customer)->get('/admin')->assertForbidden();
    }

    public function test_native_mfa_secrets_are_encrypted_at_rest_and_omitted_from_serialization(): void
    {
        $administrator = User::factory()->create();
        $secret = 'JBSWY3DPEHPK3PXP';
        $recoveryCode = 'test-recovery-code';

        $administrator->saveAppAuthenticationSecret($secret);
        AppAuthentication::make()->saveRecoveryCodes($administrator, [$recoveryCode]);
        $administrator = $administrator->fresh();

        $this->assertSame($secret, $administrator->getAppAuthenticationSecret());
        $this->assertStringNotContainsString($secret, $administrator->getRawOriginal('app_authentication_secret'));
        $this->assertStringNotContainsString($recoveryCode, $administrator->getRawOriginal('app_authentication_recovery_codes'));
        $this->assertTrue(Hash::check($recoveryCode, $administrator->getAppAuthenticationRecoveryCodes()[0]));

        $serialized = $administrator->toArray();
        $this->assertArrayNotHasKey('app_authentication_secret', $serialized);
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $serialized);
        $this->assertArrayNotHasKey('password', $serialized);
    }

    public function test_provisioning_creates_a_verified_administrator_without_default_mfa_secrets(): void
    {
        $password = 'a-long-administrator-password';

        $this->artisan('oneshop:create-admin')
            ->expectsQuestion('Name', 'Shop Administrator')
            ->expectsQuestion('Email address', 'ADMIN@example.test')
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->assertSuccessful();

        $administrator = User::query()->where('email', 'admin@example.test')->sole();

        $this->assertTrue($administrator->is_admin);
        $this->assertNotNull($administrator->email_verified_at);
        $this->assertTrue(Hash::check($password, $administrator->password));
        $this->assertNull($administrator->getAppAuthenticationSecret());
        $this->assertNull($administrator->getAppAuthenticationRecoveryCodes());
    }

    public function test_provisioning_does_not_promote_or_overwrite_an_existing_customer(): void
    {
        $customer = User::factory()->create(['email' => 'customer@example.test']);
        $originalPassword = $customer->password;

        $this->artisan('oneshop:create-admin')
            ->expectsQuestion('Name', 'Administrator')
            ->expectsQuestion('Email address', 'CUSTOMER@example.test')
            ->assertFailed();

        $this->assertFalse($customer->fresh()->is_admin);
        $this->assertSame($originalPassword, $customer->fresh()->password);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_provisioning_cannot_run_without_interactive_password_input(): void
    {
        $this->artisan('oneshop:create-admin', ['--no-interaction' => true])
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
