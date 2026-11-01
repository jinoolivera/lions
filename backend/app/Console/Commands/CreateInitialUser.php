<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Laravel\Prompts\confirm;
use Laravel\Prompts\password;
use Laravel\Prompts\text;

class CreateInitialUser extends Command
{
    protected $signature = 'lions:user:create';

    protected $description = 'Create a Lions ERP administrator account';

    public function handle(): int
    {
        $name = trim(text('Administrator name'));
        $email = mb_strtolower(trim(text('Email address')));
        $secret = password('Password (minimum 12 characters)');

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($secret) < 12) {
            $this->components->error('A name, valid email, and password of at least 12 characters are required.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error('An account already exists for that email.');

            return self::FAILURE;
        }

        if (! confirm("Create the administrator account for {$email}?", true)) {
            return self::SUCCESS;
        }

        User::query()->create(['name' => $name, 'email' => $email, 'password' => Hash::make($secret)]);
        $this->components->info('Administrator account created.');

        return self::SUCCESS;
    }
}
