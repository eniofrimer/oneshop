<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SuperAdministratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_explicit_super_admin_command_provisions_full_access_without_mfa(): void
    {
        $password = 'a-long-super-admin-password';
        $this->artisan('oneshop:create-admin', ['--super-admin' => true])
            ->expectsQuestion('Name', 'Shop Owner')
            ->expectsQuestion('Email address', 'OWNER@example.test')
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->assertSuccessful();
        $user = User::query()->where('email', 'owner@example.test')->sole();
        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->is_super_admin);
        $this->assertNull($user->getAppAuthenticationSecret());
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => $password])
            ->call('authenticate')->assertHasNoFormErrors()->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->get('/admin')->assertOk();
        $this->get('/admin/products')->assertOk();
        $this->get('/horizon')->assertOk();
        $this->assertTrue(Gate::forUser($user)->allows('any.future.ability'));
    }

    public function test_generated_password_provisioning_is_explicit_and_never_overwrites_an_account(): void
    {
        $options = ['--super-admin' => true, '--generate-password' => true,
            '--email' => 'owner@example.test', '--name' => 'Owner', '--no-interaction' => true];
        $this->assertSame(0, Artisan::call('oneshop:create-admin', $options));
        $output = Artisan::output();
        $this->assertSame(1, preg_match('/Password: ([A-Za-z0-9]{24})/', $output, $matches));
        $user = User::query()->where('email', 'owner@example.test')->sole();
        $this->assertTrue(Hash::check($matches[1], $user->password));
        $this->assertTrue($user->is_super_admin);
        $originalHash = $user->password;
        $this->assertSame(1, Artisan::call('oneshop:create-admin', $options));
        $this->assertStringNotContainsString('Password:', Artisan::output());
        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_regular_admin_still_requires_mfa_and_has_no_global_permission_bypass(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        $this->assertFalse($user->is_super_admin);
        $this->actingAs($user)->get('/admin')->assertRedirect(
            Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl()
        );
        $this->get('/horizon')->assertForbidden();
        $this->assertFalse(Gate::forUser($user)->allows('any.future.ability'));
    }

    public function test_customer_cannot_self_assign_or_serialize_super_admin_privileges(): void
    {
        $user = User::query()->create([
            'name' => 'Customer', 'email' => 'customer@example.test', 'password' => 'customer-password',
            'is_admin' => true, 'is_super_admin' => true,
        ])->fresh();
        $this->assertFalse($user->is_super_admin);
        $this->assertFalse($user->is_admin);
        $this->assertArrayNotHasKey('is_super_admin', $user->toArray());
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }
}
