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
    protected $signature = 'oneshop:create-admin';

    protected $description = 'Securely create a new administrator who must enroll an authenticator at first login';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Administrator creation requires an interactive terminal. No default credentials are generated.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        $email = Str::lower(trim((string) $this->ask('Email address')));

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
            $password = (string) $this->secret('Password', false);
            $confirmation = (string) $this->secret('Confirm password', false);
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
        $administrator->email_verified_at = now();

        try {
            $administrator->save();
        } catch (UniqueConstraintViolationException) {
            $this->error('An account with this email was created concurrently. No existing account was changed.');

            return self::FAILURE;
        }

        $this->info('Administrator created. Sign in at /admin and set up your authenticator before accessing the panel.');

        return self::SUCCESS;
    }
}
