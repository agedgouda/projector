<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    public function run()
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Global Role (No team_id)
        Role::firstOrCreate(['name' => 'super-admin', 'team_id' => null]);

        // 2. Organization Scoped Roles (Created with NULL team_id initially)
        // Spatie uses these as "templates" for teams
        $admin = Role::firstOrCreate(['name' => 'org-admin', 'team_id' => null]);
        $member = Role::firstOrCreate(['name' => 'org-member', 'team_id' => null]);

        // 3. Define Permissions
        $permissions = [
            'manage-org-users',
            'manage-org-clients',
            'view-org-reports',
            'edit-org-settings'
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }

        // 4. Assign Permissions to Scoped Roles
        $admin->givePermissionTo($permissions);
        $member->givePermissionTo(['view-org-reports']);
    }
}
