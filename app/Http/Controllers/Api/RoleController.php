<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        try {
            $page   = $request->query('page', 1);
            $limit  = $request->query('limit', 10);
            $search = mb_strtolower(trim((string) $request->query('search', '')));
            $status = strtolower(trim((string) $request->query('status', '')));
            $trashed = strtolower(trim((string) $request->query('trashed', '')));
            $currentUser = $request->user();
            $canViewTrashed = $currentUser
                && method_exists($currentUser, 'hasAnyRole')
                && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
            $showTrashed = $canViewTrashed
                && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');

            $query = SpatieRole::with('permissions')
                ->where('is_delete', $showTrashed)
                ->when($search !== '', function ($query) use ($search): void {
                    $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
                })
                ->when($request->filled('guard_name'), function ($query) use ($request): void {
                    $query->where('guard_name', $request->query('guard_name'));
                })
                ->orderByDesc('id');

            $roles = $query->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success'    => true,
                'message'    => 'Roles retrieved successfully.',
                'data'       => $roles->items(),
                'pagination' => [
                    'current_page' => $roles->currentPage(),
                    'per_page'     => $roles->perPage(),
                    'total'        => $roles->total(),
                    'last_page'    => $roles->lastPage(),
                    'from'         => $roles->firstItem(),
                    'to'           => $roles->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving roles.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
 
    public function store(Request $request)
    {
        $guardName = $request->input('guard_name') ?: 'web';
        $rolesTable = config('permission.table_names.roles', 'roles');
        $permissionsTable = config('permission.table_names.permissions', 'permissions');

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique($rolesTable, 'name')->where(fn ($query) => $query->where('guard_name', $guardName))],
            'guard_name' => ['nullable', 'string', 'max:255'],
            'permissions' => 'sometimes|array',
            'permissions.*' => [
                'string',
                Rule::exists($permissionsTable, 'name')->where(fn ($query) => $query->where('guard_name', $guardName)),
            ],
        ]);

        $this->addDuplicateRoleNameError($validator, $request->input('name'), $guardName);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validated = $validator->validated();
        $guardName = $validated['guard_name'] ?? $guardName;

        try {
            $role = DB::transaction(function () use ($validated, $guardName) {
                $role = SpatieRole::create([
                    'name' => $validated['name'],
                    'guard_name' => $guardName,
                ]);

                if (!empty($validated['permissions'])) {
                    $role->syncPermissions($validated['permissions']);
                }

                return $role->fresh('permissions');
            });
        } catch (RoleAlreadyExists|QueryException $exception) {
            if ($exception instanceof RoleAlreadyExists || $this->isDuplicateException($exception)) {
                return $this->duplicateRoleResponse();
            }

            throw $exception;
        }

        return response()->json(['status' => 'success', 'data' => $role->load('permissions')], 201);
    }

    public function show($id)
    {
        $role = SpatieRole::with('permissions')->find($id);
        if (!$role) {
            return response()->json(['status' => 'error', 'message' => 'Role not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $role], 200);
    }

    public function update(Request $request, $id)
    {
        $role = SpatieRole::with('permissions')->find($id);
        if (!$role) {
            return response()->json(['status' => 'error', 'message' => 'Role not found'], 404);
        }

        $guardName = $request->input('guard_name') ?: $role->guard_name;
        $rolesTable = config('permission.table_names.roles', 'roles');
        $permissionsTable = config('permission.table_names.permissions', 'permissions');

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique($rolesTable, 'name')->ignore($role->id)->where(fn ($query) => $query->where('guard_name', $guardName))],
            'guard_name' => ['nullable', 'string', 'max:255'],
            'permissions' => 'sometimes|array',
            'permissions.*' => [
                'string',
                Rule::exists($permissionsTable, 'name')->where(fn ($query) => $query->where('guard_name', $guardName)),
            ],
        ]);

        $this->addDuplicateRoleNameError($validator, $request->input('name'), $guardName, $role->id);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validated = $validator->validated();

        try {
            DB::transaction(function () use ($role, $validated, $guardName): void {
                $role->name = $validated['name'];
                $role->guard_name = $validated['guard_name'] ?? $guardName;
                $role->save();

                if (array_key_exists('permissions', $validated)) {
                    $role->syncPermissions($validated['permissions'] ?? []);
                }
            });
        } catch (RoleAlreadyExists|QueryException $exception) {
            if ($exception instanceof RoleAlreadyExists || $this->isDuplicateException($exception)) {
                return $this->duplicateRoleResponse();
            }

            throw $exception;
        }

        return response()->json(['status' => 'success', 'data' => $role->load('permissions')], 200);
    }

    public function destroy(Request $request, $id)
    {
        $role = SpatieRole::where('is_delete', false)->find($id);
        if (!$role) {
            return response()->json(['status' => 'error', 'message' => 'Role not found'], 404);
        }

        DB::transaction(function () use ($role, $request): void {
            $role->users()->detach();
            $role->is_active = false;
            $role->is_delete = true;
            $role->deleted_by = $request->user()?->id;
            $role->deleted_at = now();
            $role->save();
        });

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['status' => 'success', 'message' => 'Role deleted'], 200);
    }
    
    public function syncPermissions(Request $request, $id)
    {
        $role = SpatieRole::find($id);
        if (!$role) {
            return response()->json(['status' => 'error', 'message' => 'Role not found'], 404);
        }

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string',
        ]);

        $role->syncPermissions($validated['permissions']);

        return response()->json(['status' => 'success', 'data' => $role->load('permissions')], 200);
    }

    public function syncRolePermissions(Request $request, $id)
    {
        $role = SpatieRole::find($id);
        if (!$role) {
            return response()->json(['status' => 'error', 'message' => 'Role not found'], 404);
        }

        $validated = $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'integer|exists:permissions,id', // IDs validate karne ke liye
        ]);

        // Spatie automatically integer IDs ko handle kar leta hai
        $role->syncPermissions($validated['permissions']);

        return response()->json([
            'status' => 'success', 
            'data'   => $role->load('permissions')
        ], 200);
    }

    private function addDuplicateRoleNameError(
        ValidatorContract $validator,
        mixed $name,
        string $guardName,
        ?int $ignoreId = null
    ): void {
        if (!is_string($name) || trim($name) === '') {
            return;
        }

        $query = SpatieRole::where('guard_name', $guardName)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))]);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            $validator->errors()->add('name', 'A role with this name already exists.');
        }
    }

    private function validationErrorResponse(ValidatorContract $validator)
    {
        $errors = $validator->errors()->toArray();
        $isDuplicate = collect($errors)->flatten()->contains(
            fn (string $message): bool => str_contains($message, 'A role with this name already exists.')
        );

        return response()->json([
            'status' => 'error',
            'message' => $isDuplicate ? 'Role with this name already exists.' : 'Validation failed.',
            'errors' => $errors,
        ], $isDuplicate ? 409 : 422);
    }

    private function duplicateRoleResponse()
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Role with this name already exists.',
            'errors' => [
                'name' => ['A role with this name already exists.'],
            ],
        ], 409);
    }

    private function isDuplicateException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return (string) $exception->getCode() === '23505'
            || str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation');
    }


    public function getPermissions($id)
    {
        try {
            $role = SpatieRole::select('id', 'name', 'guard_name')->find($id);

            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Role not found.',
                ], 404);
            }

            $permissions = DB::table('role_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('role_has_permissions.role_id', $role->id)
                ->where('permissions.is_active', true)
                ->where('permissions.is_delete', false)
                ->select('permissions.id', 'permissions.name', 'permissions.title_name')
                ->orderBy('permissions.title_name')
                ->orderBy('permissions.name')
                ->get()
                ->groupBy(fn ($permission) => $permission->title_name ?: 'other')
                ->map(function ($group, $titleName): array {
                    return $group->mapWithKeys(function ($permission) use ($titleName): array {
                        $prefix = $titleName === 'other' ? '' : $titleName . '-';
                        $action = $prefix !== '' && str_starts_with($permission->name, $prefix)
                            ? substr($permission->name, strlen($prefix))
                            : $permission->name;

                        return [$action => $permission->id];
                    })->all();
                })
                ->all();

            return response()->json([
                'permissions' => $permissions,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving role permissions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
