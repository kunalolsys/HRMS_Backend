<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Define permissions for different models
        $models = [
            'user', 'role', 'permission', 'company', 'department', 'division',
            'cost-center', 'vendor', 'brand', 'location', 'shift', 'designation', 'employee'
        ];

        $actions = ['view', 'create', 'edit', 'delete'];

        foreach ($models as $model) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['name' => "{$model}-{$action}", 'guard_name' => 'web']);
            }
        }

        $importableModels = [
            'company', 'department', 'division', 'cost-center', 'vendor',
            'brand', 'location', 'shift', 'designation', 'employee',
        ];

        foreach ($importableModels as $model) {
            foreach (['import', 'export', 'restore'] as $action) {
                Permission::firstOrCreate(['name' => "{$model}-{$action}", 'guard_name' => 'web']);
            }
        }

        // Create a Super Admin role and assign all permissions
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $superAdminRole->givePermissionTo(Permission::all());

        // Create a standard User role and assign read-only permissions
        $userRole = Role::firstOrCreate(['name' => 'User', 'guard_name' => 'web']);
        $readOnlyPermissions = [
            'user-view',
            'role-view',
            'permission-view',
            'company-view',
            'department-view',
            'division-view',
            'cost-center-view',
            'vendor-view',
            'brand-view',
            'location-view',
            'shift-view',
            'designation-view',
            'employee-view',
        ];
        $userRole->givePermissionTo($readOnlyPermissions);

        // Create a baseline Manager role; its permissions can be adjusted by an admin.
        $managerRole = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $managerRole->givePermissionTo($readOnlyPermissions);
    }
}