<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Validation\Rule;

class PermissionAssignmentController extends Controller
{
    /**
     * Assign/Sync permissions to a specific role.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $roleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncRolePermissions(Request $request, int $roleId)
    {
        $role = SpatieRole::find($roleId);

        if (!$role) {
            return response()->json(['status' => 'error', 'message' => 'Role not found'], 404);
        }

        $permissionsTable = config('permission.table_names.permissions', 'permissions');

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => [
                'string',
                Rule::exists($permissionsTable, 'name')->where(fn ($query) => $query->where('guard_name', $role->guard_name)),
            ],
        ]);

        $role->syncPermissions($validated['permissions']);

        return response()->json([
            'status' => 'success',
            'message' => 'Permissions synced to role successfully.',
            'data' => $role->load('permissions')
        ], 200);
    }

    /**
     * Assign/Sync roles to a specific user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncUserRoles(Request $request, int $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
        }

        $rolesTable = config('permission.table_names.roles', 'roles');
        $defaultGuard = config('permission.defaults.guard') ?? 'sanctum'; // Spatie config से default guard लें या 'sanctum'
        $guardName = $request->input('guard_name', $defaultGuard); // Request से guard_name लें या default का उपयोग करें

        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => [
                'string',
                Rule::exists($rolesTable, 'name')->where(fn ($query) => $query->where('guard_name', $guardName)),
            ],
            'guard_name' => 'nullable|string|max:255', // Request में guard_name को allow करें
        ]);

        $user->syncRoles($validated['roles']);

        return response()->json([
            'status' => 'success',
            'message' => 'Roles synced to user successfully.',
            'data' => $user->load('roles')
        ], 200);
    }

    /**
     * Assign/Sync direct permissions to a specific user.
     * (This is similar to the existing method in PermissionController, but centralized here for assignment logic).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncUserDirectPermissions(Request $request, int $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
        }

        $permissionsTable = config('permission.table_names.permissions', 'permissions');
        $defaultGuard = config('permission.defaults.guard') ?? 'sanctum'; // Spatie config से default guard लें या 'sanctum'
        $guardName = $request->input('guard_name', $defaultGuard); // Request से guard_name लें या default का उपयोग करें

        $validated = $request->validate([
            'permissions' => 'present|array', // `present` ensures it's an array, even if empty
            'permissions.*' => [
                'string',
                Rule::exists($permissionsTable, 'name')->where(fn ($query) => $query->where('guard_name', $guardName)),
            ],
            'guard_name' => 'nullable|string|max:255', // Request में guard_name को allow करें
        ]);

        $user->syncPermissions($validated['permissions']);

        return response()->json([
            'status' => 'success',
            'message' => 'Direct permissions synced to user successfully.',
            'data' => $user->load('permissions')
        ], 200);
    }

    public function syncUserPermissions(Request $request, int $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
        }

        $permissionsTable = config('permission.table_names.permissions', 'permissions');

        $validated = $request->validate([
            'permissions'   => 'present|array',
            'permissions.*' => "integer|exists:{$permissionsTable},id", // Direct table ID check (bina strict guard condition ke)
            'guard_name'    => 'nullable|string|max:255',
        ]);

        // DB se Permission models fetch karke sync karein
        $permissions = \Spatie\Permission\Models\Permission::whereIn('id', $validated['permissions'])->get();

        $user->syncPermissions($permissions);

        return response()->json([
            'status'  => 'success',
            'message' => 'Direct permissions synced to user successfully.',
            'data'    => $user->load('permissions')
        ], 200);
    }
}