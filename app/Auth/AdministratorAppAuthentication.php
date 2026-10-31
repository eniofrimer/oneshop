<?php

namespace App\Auth;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Auth\Authenticatable;

class AdministratorAppAuthentication extends AppAuthentication
{
    /** @return array<Component|Action|ActionGroup> */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        $components = parent::getChallengeFormComponents($user);

        foreach ($components as $component) {
            if ($component instanceof OneTimeCodeInput) {
                // An immediate recovery submission must not be stopped by the
                // browser before the native on-blur state reaches the server.
                // The parent's required and verification rules remain intact.
                $component->extraInputAttributes(fn (Get $get): array => [
                    'required' => $component->isRequired() && ! ($this->isRecoverable() && $get('useRecoveryCode')),
                ], merge: true);
                $component->belowContent(fn (Get $get): Action => Action::make('useRecoveryCode')
                    ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.code.actions.use_recovery_code.label'))
                    ->link()
                    ->action(function (Set $set): void {
                        $set('code', null);
                        $set('useRecoveryCode', true);
                    })
                    ->visible(fn (): bool => $this->isRecoverable() && ! $get('useRecoveryCode')));
            }
        }

        return $components;
    }

    /** @return array<string> */
    public function getRecoveryCodes(HasAppAuthenticationRecovery $user): array
    {
        // The native locked verifier should refuse an exhausted list normally.
        if (blank($user->getAppAuthenticationRecoveryCodes())) {
            return [];
        }

        return parent::getRecoveryCodes($user);
    }
}
