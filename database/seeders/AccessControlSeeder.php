<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $names = [
            'automation.view',
            'automation.run',
            'automation.retry',
            'mappings.manage',
            'sessions.manage',
            'release-status.view',
            'users.manage',
        ];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'])->syncPermissions($names);
        Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web'])->syncPermissions(['release-status.view']);
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web'])->syncPermissions(['automation.view', 'automation.run', 'automation.retry', 'release-status.view']);
        Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web'])->syncPermissions(['automation.view', 'release-status.view']);
    }
}
