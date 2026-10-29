<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionTitleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $actions = ['view', 'create', 'edit', 'delete', 'import', 'export', 'restore'];
        $titles = array_unique([
            'dashboard',
            'employees',
            'onboarding',
            'documents',
            'attendance',
            'leave',
            'payroll',
            'departments',
            'performance',
            'recruitment',
            'communications',
            'user',
            'role',
            'permission',
            'company',
            'department',
            'division',
            'cost_center',
            'vendor',
            'brand',
            'location',
            'shift',
            'holiday',
            'termination',
            'asset',
            'payroll_master',
            'input_form',
            'settings',
            'designation',
            'late_deduction_slab',
            'notice_period',
            'statutory_parameter_master',
            'employee_attendance',
            'holiday',
        ]);

        foreach ($titles as $title) {
            foreach ($actions as $action) {
                Permission::updateOrCreate(
                    ['name' => "{$title}-{$action}", 'guard_name' => 'web'],
                    ['title_name' => $title],
                );
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}