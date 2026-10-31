<?php

namespace Tests\Feature;

use App\Auth\AdministratorAppAuthentication;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AdministratorChallengeTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private string $password;

    private string $recoveryCode;

    protected function setUp(): void
    {
        parent::setUp();
        if (blank(config('app.key'))) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->password = Str::password(32);
        $this->recoveryCode = Str::random(10).'-'.Str::random(10);
        $this->administrator = User::factory()->create(['password' => $this->password]);
        $this->administrator->forceFill(['is_admin' => true])->save();
        $provider = AdministratorAppAuthentication::make();
        $this->administrator->saveAppAuthenticationSecret($provider->generateSecret());
        $provider->saveRecoveryCodes($this->administrator, [$this->recoveryCode]);
    }

    private function challenge(): Testable
    {
        $page = Livewire::test(Login::class)->fillForm([
            'email' => $this->administrator->email, 'password' => $this->password,
        ])->call('authenticate')->assertHasNoFormErrors();
        $this->assertGuest('web');

        return $page;
    }

    private function otpHtmlRequired(Testable $page): bool
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($page->html());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $input = (new \DOMXPath($document))->query('//input[contains(concat(" ",normalize-space(@class)," ")," fi-one-time-code-input-digit ")]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $input);

        return $input->hasAttribute('required');
    }

    public function test_recovery_mode_changes_only_browser_required_and_empty_codes_are_refused_by_server(): void
    {
        $page = $this->challenge();
        $this->assertTrue($this->otpHtmlRequired($page));
        $page->set('data.multiFactor.app.useRecoveryCode', true);
        $this->assertFalse($this->otpHtmlRequired($page));
        $page->call('authenticate')->assertHasFormErrors(['app.code' => 'required'], 'multiFactorChallengeForm');
        $this->assertGuest('web');
        $this->assertCount(1, $this->administrator->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_wrong_recovery_cannot_authenticate_or_consume_a_code(): void
    {
        $this->challenge()->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', Str::random(10).'-'.Str::random(10))
            ->call('authenticate')->assertHasFormErrors(['app.recoveryCode'], 'multiFactorChallengeForm');
        $this->assertGuest('web');
        $this->assertCount(1, $this->administrator->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_last_recovery_is_accepted_once_and_reuse_is_a_validation_error(): void
    {
        $this->challenge()->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $this->recoveryCode)
            ->call('authenticate')->assertHasNoFormErrors([], 'multiFactorChallengeForm');
        $this->assertAuthenticatedAs($this->administrator, 'web');
        $this->assertSame([], $this->administrator->fresh()->getAppAuthenticationRecoveryCodes());
        Auth::logout();

        $page = $this->challenge()->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $this->recoveryCode)
            ->call('authenticate')->assertHasFormErrors(['app.recoveryCode'], 'multiFactorChallengeForm');
        $this->assertGuest('web');
        $page->set('data.multiFactor.app.recoveryCode', null)
            ->set('data.multiFactor.app.code', AdministratorAppAuthentication::make()->getCurrentCode($this->administrator))
            ->call('authenticate')->assertHasNoFormErrors([], 'multiFactorChallengeForm');
        $this->assertAuthenticatedAs($this->administrator, 'web');
    }

    public function test_missing_recovery_codes_are_refused_without_disabling_totp(): void
    {
        $this->administrator->saveAppAuthenticationRecoveryCodes(null);
        $provider = AdministratorAppAuthentication::make();
        $this->assertFalse($provider->verifyRecoveryCode($this->recoveryCode, $this->administrator));
        $this->assertTrue($provider->isEnabled($this->administrator));
        $this->assertNotNull($this->administrator->fresh()->getAppAuthenticationSecret());
    }

    public function test_password_changed_during_challenge_cannot_spend_a_recovery_code_or_log_in(): void
    {
        $page = $this->challenge();
        $this->administrator->update(['password' => Str::password(32)]);
        $page->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $this->recoveryCode)
            ->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest('web');
        $this->assertCount(1, $this->administrator->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_administrator_revoked_during_challenge_cannot_spend_a_recovery_code_or_log_in(): void
    {
        $page = $this->challenge();
        $this->administrator->forceFill(['is_admin' => false])->save();
        $page->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $this->recoveryCode)
            ->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest('web');
        $this->assertCount(1, $this->administrator->fresh()->getAppAuthenticationRecoveryCodes());
    }
}
