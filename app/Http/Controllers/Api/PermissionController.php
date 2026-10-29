<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission as SpatiePermission;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;
use DB;
class PermissionController extends Controller
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

            $filteredPermissions = SpatiePermission::query()
                ->where('is_delete', $showTrashed)
                ->when($request->filled('guard_name'), function ($query) use ($request): void {
                    $query->where('guard_name', $request->query('guard_name'));
                })
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->whereRaw('LOWER(title_name) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
                    });
                });

            $paginatedTitles = (clone $filteredPermissions)
                ->select('title_name')
                ->groupBy('title_name')
                ->orderBy('title_name', 'asc')
                ->paginate($limit, ['*'], 'page', $page);

            $titleNames = $paginatedTitles->getCollection()
                ->pluck('title_name')
                ->all();

            $permissions = (clone $filteredPermissions)
                ->where(function ($query) use ($titleNames): void {
                    if ($titleNames === []) {
                        $query->whereRaw('1 = 0');
                        return;
                    }

                    $nonNullTitles = array_values(array_filter($titleNames, fn ($title) => $title !== null));
                    if ($nonNullTitles !== []) {
                        $query->whereIn('title_name', $nonNullTitles);
                    }
                    if (in_array(null, $titleNames, true)) {
                        $method = $nonNullTitles === [] ? 'whereNull' : 'orWhereNull';
                        $query->{$method}('title_name');
                    }
                })
                ->select('id', 'name', 'title_name')
                ->orderBy('title_name')
                ->orderBy('name')
                ->get()
                ->groupBy(fn (SpatiePermission $permission) => $permission->title_name ?: 'other')
                ->map(function ($group, $titleName): array {
                    return $group->mapWithKeys(function (SpatiePermission $permission) use ($titleName): array {
                        $prefix = $titleName === 'other' ? '' : $titleName . '-';
                        $action = $prefix !== '' && str_starts_with($permission->name, $prefix)
                            ? substr($permission->name, strlen($prefix))
                            : $permission->name;

                        return [$action => $permission->id];
                    })->all();
                })
                ->all();

            return response()->json([
                'success'    => true,
                'message'    => 'Permissions retrieved successfully.',
                'permissions' => $permissions,
                'pagination' => [
                    'current_page' => $paginatedTitles->currentPage(),
                    'per_page'     => $paginatedTitles->perPage(),
                    'total'        => $paginatedTitles->total(),
                    'last_page'    => $paginatedTitles->lastPage(),
                    'from'         => $paginatedTitles->firstItem(),
                    'to'           => $paginatedTitles->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving permissions.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request) 
    {
        $titleName = trim((string) $request->input('title_name', ''));
        $guardName = $request->input('guard_name', 'web') ?: 'web';

        // Agar request me single 'name' pass kiya ho to use 'names' array me convert karein
        if ($request->filled('name') && !$request->has('names')) {
            $request->merge(['names' => (array) $request->input('name')]);
        }

        $validator = Validator::make($request->all(), [
            'title_name' => ['required', 'string', 'max:255'],
            'guard_name' => ['nullable', 'string', 'max:255'],
            'names'      => ['required', 'array', 'min:1'],
            'names.*'    => ['required', 'string', 'max:255', 'distinct'],
        ], [
            'names.*.distinct' => 'The permission :input is duplicated in the request list.',
        ]);

        // Custom duplicate check: title_name aur name ke combination ke according (case-insensitive)
        $validator->after(function ($validator) use ($titleName, $guardName, $request) {
            $names = $request->input('names', []);
            if (!is_array($names) || $titleName === '') {
                return;
            }

            foreach ($names as $index => $name) {
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }        
                $trimmedName = mb_strtolower(trim($name));
                $titleName = mb_strtolower(trim($titleName));
                $permissionName = $titleName.'-'.$trimmedName;
                $alreadyExists = SpatiePermission::where('guard_name', $guardName)
                    ->whereRaw('LOWER(title_name) = ?', [mb_strtolower($titleName)])
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($permissionName)])
                    ->exists();

                if ($alreadyExists) {
                    $validator->errors()->add(
                        "names.{$index}",
                        "The permission '{$trimmedName}' already exists for title_name '{$titleName}'."
                    );
                }
            }
        });

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
            $isDuplicate = collect($errors)->flatten()->contains(fn ($msg) => str_contains(strtolower($msg), 'already exists'));

            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => $isDuplicate ? 'Permission with this title_name and name already exists.' : 'Validation failed.',
                'errors'  => $errors,
            ], $isDuplicate ? 409 : 422);
        }

        try {
            $createdPermissions = [];

            DB::transaction(function () use ($validator, $titleName, $guardName, &$createdPermissions) {
                $validated = $validator->validated();
                foreach ($validated['names'] as $permissionName) {
                    $trimmedName = mb_strtolower(trim($permissionName));
                    $titleName = mb_strtolower(trim($titleName));
                    $permission_name = $titleName.'-'.$trimmedName;
                    $createdPermissions[] = SpatiePermission::create([
                        'name'       => trim($permission_name),
                        'title_name' => $titleName,
                        'guard_name' => $guardName,
                    ]);
                }

                app()[PermissionRegistrar::class]->forgetCachedPermissions();
            });

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Permissions created successfully.',
                'data'    => $createdPermissions,
            ], 201);
        } catch (QueryException $e) {
            $isDuplicate = $e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'duplicate');
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => $isDuplicate ? 'Permission record already exists.' : 'Failed to create permissions.',
                'error'   => $e->getMessage(),
            ], $isDuplicate ? 409 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'An error occurred while creating permissions.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $permission = SpatiePermission::with('roles')
            ->where('is_delete', false)
            ->select('id', 'name', 'title_name', 'guard_name', 'created_at', 'updated_at')
            ->find($id);
        if (!$permission) {
            return response()->json(['status' => 'error', 'message' => 'Permission not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $permission], 200);
    }

    public function update(Request $request, $id)
    {
        $permission = SpatiePermission::where('is_delete', false)->find($id);
        if (!$permission) {
            return response()->json(['status' => 'error', 'message' => 'Permission not found'], 404);
        }

        $permModel = new SpatiePermission();
        // Determine the guard_name to use for the unique validation check.
        // If guard_name is provided in the request, use it. Otherwise, use the existing permission's guard_name.
        $currentGuardNameForValidation = $request->input('guard_name') ?? $permission->guard_name;
        $currentTitleNameForValidation = $request->input('title_name') ?? $permission->title_name; // Get title_name for validation

        // Note: The 'name' validation rule already correctly incorporates 'guard_name' and 'title_name'
        // for uniqueness check, ignoring the current permission's ID.
        // This ensures that the combination of name, title_name, and guard_name remains unique
        // across all permissions (excluding the one being updated).
        // The 'store' method uses a similar logic for multiple names, ensuring consistency.
        // The 'title_name' and 'guard_name' fields are updated only if they are explicitly
        // provided in the request, otherwise, their existing values are retained.


        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique($permModel->getTable(), 'name')->ignore($permission->id, 'id')
                    ->where(fn ($query) => $query->where('guard_name', $currentGuardNameForValidation)
                                                 ->where('title_name', $currentTitleNameForValidation)), // Add title_name to uniqueness check
            ], // This rule correctly checks uniqueness based on name, guard_name, and title_name
            'title_name' => 'required|string|max:255',
            'guard_name' => 'nullable|string|max:255', // Removed `default:web` as it's handled explicitly below
        ]);

        $permission->name = $validated['name'];
        if (array_key_exists('title_name', $validated)) { // Check if title_name was provided in the request
            $permission->title_name = $validated['title_name'];
        }
        if (array_key_exists('guard_name', $validated)) { // Update guard_name if explicitly provided
            $permission->guard_name = $validated['guard_name'];
        }
        $permission->save();

        return response()->json(['status' => 'success', 'data' => $permission], 200);
    }

    /**
     * Update permissions grouped by title_name.
     * Adds new permissions, unsets removed ones after verifying they are not assigned to roles/users.
     */
    public function updateByTitleName(Request $request, ?string $titleName = null)
    {
        $currentTitleName = mb_strtolower(trim((string) ($titleName ?? $request->input('title_name', ''))));
        $guardName = $request->input('guard_name', 'web') ?: 'web';

        $validated = $request->validate([
            'title_name'     => 'nullable|string|max:255',
            'new_title_name' => 'nullable|string|max:255',
            'names'          => 'present|array',
            'names.*'        => 'required|string|max:255|distinct',
            'guard_name'     => 'nullable|string|max:255',
        ]);

        if ($currentTitleName === '') {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'The title_name parameter is required.'
            ], 422);
        }

        $existingPermissions = SpatiePermission::whereRaw('LOWER(title_name) = ?', [$currentTitleName])
            ->where('guard_name', $guardName)
            ->where('is_delete', false)
            ->get();

        if ($existingPermissions->isEmpty()) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => "No permissions found for title_name: '{$currentTitleName}'."
            ], 404);
        }

        $targetTitleName = mb_strtolower(trim((string) (
            $validated['new_title_name']
            ?? $validated['title_name']
            ?? $currentTitleName
        )));

        // Target permissions map taiyar karein: [action => '{title_name}_{action}']
        $targetActions = [];
        foreach ($validated['names'] as $name) {
            $trimmed = mb_strtolower(trim($name));
            if ($trimmed === '') {
                continue;
            }

            // Agar user ne already prefix diya ho to action extract karein
            $action = $trimmed;
            foreach ([$targetTitleName, $currentTitleName] as $prefix) {
                foreach (['-', '_'] as $separator) {
                    $fullPrefix = $prefix . $separator;
                    if (str_starts_with($action, $fullPrefix)) {
                        $action = substr($action, strlen($fullPrefix));
                        break 2;
                    }
                }
            }

            $targetActions[$action] = $targetTitleName . '-' . $action;
        }

        $existingActions = $existingPermissions->mapWithKeys(function ($permission) {
            $permName = mb_strtolower(trim($permission->name));
            $titleName = mb_strtolower(trim((string) $permission->title_name));
            $action = $permName;

            foreach (['-', '_'] as $separator) {
                $prefix = $titleName . $separator;
                if ($titleName !== '' && str_starts_with($action, $prefix)) {
                    $action = substr($action, strlen($prefix));
                    break;
                }
            }

            return [$action => $permission];
        });

        // Unset / Remove hone wali permissions identify karein
        $actionsToRemove = array_diff(array_keys($existingActions->all()), array_keys($targetActions));
        $permissionsToRemove = collect();
        foreach ($actionsToRemove as $action) {
            $permission = $existingActions[$action];
            $isAssigned = $permission->roles()->exists() || $permission->users()->exists();

            if ($isAssigned) {
                $targetActions[$action] = $targetTitleName . '-' . $action;
            } else {
                $permissionsToRemove->push($permission);
            }
        }

        $actionsToCreate = array_diff(array_keys($targetActions), array_keys($existingActions->all()));

        try {
            \DB::transaction(function () use ($permissionsToRemove, $existingActions, $targetActions, $actionsToCreate, $targetTitleName, $guardName) {
                // 1. Unset permissions ko delete karein
                foreach ($permissionsToRemove as $permission) {
                    $permission->delete();
                }

                // 2. Existing permissions ka name aur title_name store format ke hisab se sync karein
                foreach ($existingActions as $action => $permission) {
                    if (!isset($targetActions[$action])) {
                        continue;
                    }
                    $expectedFullName = $targetActions[$action];
                    if ($permission->name !== $expectedFullName || $permission->title_name !== $targetTitleName || $permission->guard_name !== $guardName) {
                        $permission->update([
                            'name'       => $expectedFullName,
                            'title_name' => $targetTitleName,
                            'guard_name' => $guardName,
                        ]);
                    }
                }

                // 3. Nayi permissions store method ke jaise {title_name}_{name} format me create karein
                foreach ($actionsToCreate as $action) {
                    SpatiePermission::create([
                        'name'       => $targetActions[$action],
                        'title_name' => $targetTitleName,
                        'guard_name' => $guardName,
                    ]);
                }

                app()[PermissionRegistrar::class]->forgetCachedPermissions();
            });
        } catch (QueryException $e) {
            $isDuplicate = $e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'duplicate');
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => $isDuplicate ? 'Permission record already exists.' : 'Failed to update permissions.',
                'error'   => $e->getMessage(),
            ], $isDuplicate ? 409 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'An error occurred while updating permissions.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        $updatedPermissions = SpatiePermission::whereRaw('LOWER(title_name) = ?', [$targetTitleName])
            ->where('guard_name', $guardName)
            ->where('is_delete', false)
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success'    => true,
            'status'     => 'success',
            'message'    => 'Permissions updated successfully.',
            'title_name' => $targetTitleName,
            'data'       => $updatedPermissions,
        ], 200);
    }

    public function destroy(Request $request, $id)
    {
        if (!ctype_digit((string) $id)) {
            return response()->json(['status' => 'error', 'message' => 'Permission not found'], 404);
        }

        $permission = SpatiePermission::where('is_delete', false)->find($id);
        if (!$permission) {
            return response()->json(['status' => 'error', 'message' => 'Permission not found'], 404);
        }

        // Check if the permission is assigned to any roles
        if ($permission->roles()->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Permission cannot be deleted as it is assigned to one or more roles.'], 409);
        }

        // Check if the permission is assigned to any users directly
        if ($permission->users()->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Permission cannot be deleted as it is directly assigned to one or more users.'], 409);
        }

        $permission->is_delete = true;
        $permission->is_active = false;
        $permission->deleted_by = $request->user()?->id;
        $permission->deleted_at = now();
        $permission->save();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['status' => 'success', 'message' => 'Permission deleted'], 200);
    }
    // Function to sync roles for a specific permission
    public function syncRoles(Request $request, $id)
    {
        $permission = SpatiePermission::where('is_delete', false)->find($id);
        if (!$permission) {
            return response()->json(['status' => 'error', 'message' => 'Permission not found'], 404);
        }

        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'string',
        ]);

        $permission->syncRoles($validated['roles']);

        return response()->json(['status' => 'success', 'data' => $permission->load('roles')], 200);
    }

    // added function to assign permissions to a user directly 
    public function assignUserPermissions(Request $request, $userId)
    {
        $user = User::findOrFail($userId);

        $validated = $request->validate([
            'permissions'   => 'present|array', // Khali array [] se saari direct permissions remove bhi ho sakti hain
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'name')
                    ->where(fn ($query) => $query->where('is_delete', false)),
            ],
        ]);

        // Ye line database ki `model_has_permissions` table me direct save/update kar degi
        $user->syncPermissions($validated['permissions']);

        return response()->json([
            'status'             => 'success',
            'message'            => 'User permissions updated successfully in database',
            'direct_permissions' => $user->getDirectPermissions()->map(function ($permission) {
                return [
                    'name'       => $permission->name,
                    'title_name' => $permission->title_name,
                ];
            }),
            'all_permissions'    => $user->getAllPermissions()->map(function ($permission) {
                return [
                    'name'       => $permission->name,
                    'title_name' => $permission->title_name,
                ];
            }),
        ], 200);
    }

    // Function to get all permissions for a specific user
    public function getUserPermissions($userId)
    {
        // Load permissions with 'id', 'name', and 'title_name'
        $user = User::with(['roles:id,name', 'permissions:id,name,title_name'])->findOrFail($userId);

        // Fetch direct permissions with name and title_name
        $directPermissions = $user->getDirectPermissions()->map(function ($permission) {
            return ['name' => $permission->name, 'title_name' => $permission->title_name];
        });

        // Fetch permissions via roles with name and title_name
        $permissionsViaRoles = $user->getPermissionsViaRoles()->map(function ($permission) {
            return ['name' => $permission->name, 'title_name' => $permission->title_name];
        });

        // Fetch all permissions with name and title_name
        $allPermissions = $user->getAllPermissions()->map(function ($permission) {
            return ['name' => $permission->name, 'title_name' => $permission->title_name];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'user_id' => $user->id,
                'direct_permissions' => $directPermissions,
                'permissions_via_roles' => $permissionsViaRoles,
                'all_permissions' => $allPermissions,
            ]
        ], 200);
    }

    public function getGroupedPermissions()
    {
        $permissions = SpatiePermission::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->select(['id', 'name', 'title_name'])
            ->orderBy('title_name')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SpatiePermission $permission) => $permission->title_name ?: 'other')
            ->map(function ($group, $titleName) {
                return $group->mapWithKeys(function (SpatiePermission $permission) use ($titleName) {
                    $prefix = $titleName === 'other' ? '' : $titleName . '-';
                    $action = $prefix !== '' && str_starts_with($permission->name, $prefix)
                        ? substr($permission->name, strlen($prefix))
                        : $permission->name;

                    return [$action => $permission->id];
                });
            })
            ->all();

        return response()->json([
            'status' => 'success',
            'permissions' => $permissions,
        ], 200);
    }

    public function softDeleteByTitle(Request $request, string $titleName)
    {
        $titleName = mb_strtolower(trim($titleName));
        $guardName = $request->input('guard_name', 'web') ?: 'web';

        if ($titleName === '') {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'The title_name parameter is required.',
            ], 422);
        }

        $permissions = SpatiePermission::whereRaw('LOWER(title_name) = ?', [$titleName])
            ->where('guard_name', $guardName)
            ->where('is_delete', false)
            ->get();

        if ($permissions->isEmpty()) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => "No active permissions found for Title Name: '{$titleName}'.",
            ], 404);
        }

        \DB::transaction(function () use ($permissions, $request): void {
            foreach ($permissions as $permission) {
                $permission->roles()->detach();
                $permission->users()->detach();
                $permission->update([
                    'is_delete' => true,
                    'is_active' => false,
                    'deleted_by' => $request->user()?->id,
                    'deleted_at' => now(),
                ]);
            }
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => "Permissions for Title Name: '{$titleName}' soft deleted successfully.",
            'deleted_count' => $permissions->count(),
        ], 200);
    }
}
