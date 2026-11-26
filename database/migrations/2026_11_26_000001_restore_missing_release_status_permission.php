<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Older installations were migrated without rerunning the access seeder.
        // Repair only a missing permission; preserve deliberate role restrictions.
        if (Permission::where('name', 'release-status.view')->where('guard_name', 'web')->exists()) {
            return;
        }

        $permission = Permission::create(['name' => 'release-status.view', 'guard_name' => 'web']);
        foreach (Role::where('guard_name', 'web')->whereIn('name', ['Admin', 'Staff', 'Operator', 'Viewer'])->get() as $role) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep access data when rolling back application code.
    }
};
