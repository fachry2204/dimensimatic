<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class BootstrapInstalledApp extends Command
{
    protected $signature = 'dimensimatic:bootstrap';
    protected $description = 'Create the initial local administrator account';

    public function handle(): int
    {
        $user = User::updateOrCreate(['username' => 'admin'], [
            'name' => 'Administrator', 'email' => 'admin@dimensimatic.local',
            'password' => 'admin', 'is_active' => true,
        ]);
        $user->assignRole('Admin');
        $this->info('Local administrator is ready.');

        return self::SUCCESS;
    }
}
