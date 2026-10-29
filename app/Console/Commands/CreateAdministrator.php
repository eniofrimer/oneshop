<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class CreateAdministrator extends Command
{
    protected $signature = 'oneshop:create-admin
        {--super-admin : Grant all application abilities and exempt this account from required MFA}
        {--generate-password : Generate a random password and display it once; requires --name and --email}
        {--name= : Name for generated-password provisioning}
        {--email= : Email for generated-password provisioning}';

    protected $description = 'Create an administrator; --super-admin grants all abilities without required MFA';

    public function handle(): int
    {
        $generatePassword = (bool) $this->option('generate-password');
        if (! $this->input->isInteractive() && ! $generatePassword) {
            $this->error('Administrator creation requires an interactive terminal. No default credentials are generated.');

            return self::FAILURE;
        }

        $name = trim((string) ($generatePassword ? $this->option('name') : $this->ask('Name')));
        $email = Str::lower(trim((string) ($generatePassword ? $this->option('email') : $this->ask('Email address'))));

        $identityValidator = Validator::make([
            'name' => $name,
            'email' => $email,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        if ($identityValidator->fails()) {
            foreach ($identityValidator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error('An account with this email already exists. This command never promotes or overwrites an existing account.');

            return self::FAILURE;
        }

        try {
            $password = $generatePassword ? Str::password(24, symbols: false) : (string) $this->secret('Password', false);
            $confirmation = $generatePassword ? $password : (string) $this->secret('Confirm password', false);
        } catch (RuntimeException) {
            $this->error('The terminal cannot hide password input. Use an interactive terminal with hidden input support.');

            return self::FAILURE;
        }

        $passwordValidator = Validator::make([
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'password' => [
                'required',
                'string',
                Password::min(12),
                'confirmed',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                },
            ],
        ]);

        if ($passwordValidator->fails()) {
            foreach ($passwordValidator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $administrator = new User([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        $administrator->is_admin = true;
        $administrator->is_super_admin = (bool) $this->option('super-admin');
        $administrator->email_verified_at = now();

        try {
            $administrator->save();
        } catch (UniqueConstraintViolationException) {
            $this->error('An account with this email was created concurrently. No existing account was changed.');

            return self::FAILURE;
        }

        $this->info($administrator->is_super_admin
            ? 'Super administrator created with all application abilities. Sign in at /admin; MFA enrollment is optional.'
            : 'Administrator created. Sign in at /admin and set up your authenticator before accessing the panel.');

        if ($generatePassword) {
            $this->line('Email: '.$email);
            $this->line('Password: '.$password);
        }

        return self::SUCCESS;
    }
}
