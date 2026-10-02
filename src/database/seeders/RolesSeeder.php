<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesSeeder extends Seeder
{
    /**
     * Global role plane (spec 001 AC-001.23). Team-plane roles arrive in 002.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $wildcard = Permission::findOrCreate('*', 'web');
        $admin = Role::findOrCreate('super-admin', 'web');
        $admin->syncPermissions([$wildcard]);

        Role::findOrCreate('user', 'web');
    }
}
