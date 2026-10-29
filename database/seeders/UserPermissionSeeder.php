<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class UserPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = Permission::where('guard_name', 'web')->get();

        if ($permissions->isEmpty()) {
            throw new RuntimeException('No web permissions exist to assign to users.');
        }

        User::query()->chunkById(100, function ($users) use ($permissions): void {
            foreach ($users as $user) {
                $user->syncPermissions($permissions);
            }
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
