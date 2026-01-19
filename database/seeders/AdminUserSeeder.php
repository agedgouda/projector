<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
    Role::firstOrCreate(['name' => 'admin']);
    // Create the admin user
        $user = User::updateOrCreate(
            ['email' => 'admin@test.com'], // Checks if this email exists first
            [
                'first_name' => 'Admin',
                'last_name'  => 'User',
                'password'   => Hash::make('password'), // Change to something secure
                'email_verified_at' => now(),
            ]
        );

        // Since Spatie is working, this will insert into 'model_has_roles'
        // using the role name 'admin' from your 'roles' table.
        $user->assignRole('admin');
    }
}
