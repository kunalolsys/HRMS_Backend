<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Call the seeder for roles and permissions first
        $this->call(RoleAndPermissionSeeder::class);
        $this->call(PermissionTitleSeeder::class);
        $this->call(SuperAdminPermissionSeeder::class);

        // Create a test user and assign the Super Admin role
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password', // Explicitly set password
        ]);

        // The 'Super Admin' role is created in RoleAndPermissionSeeder
        $user->assignRole('Super Admin');

        // User::factory(10)->create();
        $this->call(DesignationSeeder::class);
        $this->call(DivisionSeeder::class);
        $this->call(CompanySeeder::class);
        $this->call(CostCenterSeeder::class);
        $this->call(LocationSeeder::class);
        $this->call(AttendanceDeductionSlabSeeder::class);
    }
}
