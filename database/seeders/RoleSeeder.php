<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
        $projectLead = Role::firstOrCreate(['name' => 'project-lead', 'team_id' => null]);
        $teamMember = Role::firstOrCreate(['name' => 'team-member', 'team_id' => null]);

        // Remove deprecated org-member role
        Role::where('name', 'org-member')->delete();

        // 3. Define Permissions
        $permissions = [
            'manage-org-users',
            'manage-org-clients',
            'view-org-reports',
            'edit-org-settings',
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }

        // 4. Assign Permissions to Scoped Roles
        $admin->givePermissionTo($permissions);
        $projectLead->givePermissionTo(['view-org-reports', 'manage-org-clients']);
        $teamMember->givePermissionTo(['view-org-reports']);
    }
}
