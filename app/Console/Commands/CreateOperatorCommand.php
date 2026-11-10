<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

final class CreateOperatorCommand extends Command
{
    protected $signature = 'soundon:operator:create {username} {--name=Operator}';

    protected $description = 'Create or reactivate an operator without exposing the password';

    public function handle(): int
    {
        $password = (string) $this->secret('Password (minimum 12 characters)');
        if (mb_strlen($password) < 12) {
            $this->error('Password must contain at least 12 characters.');

            return self::INVALID;
        }
        $username = strtolower(trim((string) $this->argument('username')));
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]{2,49}$/', $username)) {
            $this->error('Username must be 3-50 characters and use letters, numbers, dot, dash, or underscore.');

            return self::INVALID;
        }
        $user = User::updateOrCreate(['username' => $username], ['name' => (string) $this->option('name'), 'email' => null, 'password' => $password, 'is_active' => true]);
        if (Role::where('name', 'Admin')->exists()) {
            $user->syncRoles(['Admin']);
        }
        $this->info('Operator account is ready.');

        return self::SUCCESS;
    }
}
