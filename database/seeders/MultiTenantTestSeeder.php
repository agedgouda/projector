<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class MultiTenantTestSeeder extends Seeder
{
    public function run(): void
    {
        $orgName = 'Gouda Industries';
        $orgId = '019b5e1b-6799-70fe-827a-f68ab26e74be';

        // 1. Create the Organization with ALL required fields
        $org = Organization::firstOrCreate(
            ['id' => $orgId],
            [
                'name' => $orgName,
                'slug' => Str::slug($orgName),
                'normalized_name' => Str::upper($orgName), // Assuming normalization is uppercase
            ]
        );

        // 2. Setup Roles and Users as before
        $adminUser = User::where('email', 'agedgouda@gmail.com')->first();
        $memberUser = User::where('email', 'aged.gouda@gmail.com')->first();

        $orgAdminRole = Role::firstOrCreate([
            'name' => 'org-admin',
            'team_id' => $org->id
        ]);

        // Lock Spatie context to this team
        setPermissionsTeamId($org->id);

        if ($adminUser) {
            $adminUser->organizations()->syncWithoutDetaching([$org->id]);
            $adminUser->assignRole($orgAdminRole);
        }

        if ($memberUser) {
            $memberUser->organizations()->syncWithoutDetaching([$org->id]);
        }

        // 3. Connect the specific Client UUID
        // Make sure we are looking for the client ID you provided earlier
        $client = Client::find('019b5e1b-6799-70fe-827a-f68ab26e74be');
        if ($client) {
            $client->update(['organization_id' => $org->id]);
            if ($adminUser) $client->users()->syncWithoutDetaching([$adminUser->id]);
        }
    }
}
