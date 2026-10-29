<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    // Sabhi users ka data fetch karne ke liye
    public function index(Request $request)
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $trashed = strtolower(trim((string) $request->query('trashed', '')));
        $currentUser = $request->user();
        $canViewTrashed = $currentUser
            && method_exists($currentUser, 'hasAnyRole')
            && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
        $showTrashed = $canViewTrashed
            && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');

        $usersQuery = User::query()
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'users.is_active',
                'users.email_verified_at',
                'users.created_at'
            )
            ->with('roles:id,name')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->whereRaw('LOWER(users.name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(users.email) LIKE ?', ["%{$search}%"])
                        ->orWhereHas('roles', function ($roleQuery) use ($search): void {
                            $roleQuery->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
                        });
                });
            })
            ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                $isActive = in_array($status, ['active', '1', 'true'], true);
                $query->where('is_active', $isActive);
            })
            ->where('users.is_delete', $showTrashed)
            ->orderBy('users.id', 'DESC');

        $formatUsers = static function ($users): void {
            $users->each(function (User $user): void {
                $user->setAttribute('role_name', $user->roles->first()?->name);
                $user->unsetRelation('roles');
            });
        };

        if (!$request->query->has('page') && !$request->query->has('limit')) {
            $users = $usersQuery->get();
            $formatUsers($users);

            return response()->json($users->values(), 200);
        }

        $users = $usersQuery->paginate(
            $request->query('limit', 10),
            ['*'],
            'page',
            $request->query('page', 1)
        );

        $formatUsers($users->getCollection());

        return response()->json([
            'success' => true, 
            'message' => 'Users list retrieved successfully!',
            'data' => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ], 200);
    }

    // Kisi single user ka data fetch karne ke liye (by ID).
    public function show($id)
    {
        $user = User::select('id', 'name', 'email', 'created_at')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found!'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'User details retrieved successfully!',
            'data' => $user
        ], 200);
    }

    /**
     * Retrieve permissions assigned directly to a user.
     */
    

    /**
     * User ki details update karne ke liye.
     */
    public function update(Request $request, $id)
    {
        $validatedData = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($id),
            ],
            'password' => 'sometimes|nullable|string|min:8|confirmed',
            'role'     => 'sometimes|string|exists:roles,name',
        ]);

        try {
            $user = User::findOrFail($id);

            DB::transaction(function () use ($user, $validatedData) {
                // Updated values set karein
                if (isset($validatedData['name'])) {
                    $user->name = $validatedData['name'];
                }
                if (isset($validatedData['email'])) {
                    $user->email = $validatedData['email'];
                }
                if (!empty($validatedData['password'])) {
                    $user->password = Hash::make($validatedData['password']);
                }

                $user->save();

                // Role update logic
                if (!empty($validatedData['role'])) {
                    // Agar Guard mismatch ka issue aaye toh specific guard pass karein
                    $user->syncRoles([$validatedData['role']]);
                }
            });

            // Response ke liye roles load karein ('roles:name' ki jagah pura 'roles' load karein)
            $user->load('roles');

            return response()->json([
                'success' => true, 
                'message' => 'User updated successfully!', 
                'data'    => $user
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'An error occurred while updating the user.', 
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function directPermissions($id)
    {
        try {
            $user = User::select('id', 'name', 'email')->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found!',
                ], 404);
            }

            $permissions = DB::table('model_has_permissions as user_permissions')
                ->join('users', 'users.id', '=', 'user_permissions.model_id')
                ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
                ->where('user_permissions.model_type', User::class)
                ->where('users.id', $id)
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
                'message' => 'An error occurred while retrieving user permissions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
