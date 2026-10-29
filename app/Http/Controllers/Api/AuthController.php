<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    // login
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // 2. User Check Karein
        $user = User::where('email', $request->email)->first();

        // 3. Password Verification
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.'
            ], 401);
        }

        // 4. Token Generate Karein (Purane tokens delete karne ka optional option)
        $user->tokens()->delete(); // Purani sessions clear karne ke liye
        //$token = $user->createToken('auth_token')->plainTextToken;

        $token = $user->createToken('auth_token', ['*'], now()->addHours(24))->plainTextToken;

        $user->load('roles:id,name');
        $roles = $user->getRoleNames()->values();
        $primaryRole = $roles->first();
        $directPermissions = $user->getDirectPermissions();
        $permissionsToReturn = $directPermissions->isNotEmpty()
            ? $directPermissions
            : $user->getPermissionsViaRoles();
        $permissions = $permissionsToReturn
            ->groupBy(fn ($permission) => $permission->title_name ?: 'other')
            ->map(function ($group, $titleName) {
                return $group->map(function ($permission) use ($titleName) {
                    $prefix = $titleName === 'other' ? '' : $titleName . '-';

                    return $prefix !== '' && str_starts_with($permission->name, $prefix)
                        ? substr($permission->name, strlen($prefix))
                        : $permission->name;
                })->values();
            })
            ->all();
        $user->setAttribute('role', $primaryRole);
        $user->setRelation('permissions', $permissions);

        // 5. Response Return Karein
        return response()->json([
            'success' => true,
            'message' => 'User logged in successfully!',
            'data' => $user,
            'role' => $primaryRole,
            'roles' => $roles,
            'permissions' => $permissions,
            'token' => $token
        ], 200);
    }
    // user register
    public function register(Request $request)
    {
        // 1. Data Validation
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'role' => [
                'required',
                'string',
                'max:255',
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed', // 'confirmed' ke liye React se 'password_confirmation' field bhejna hoga
        ]);

        // 2. User Create Karein
        $user = User::create([
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'password' => Hash::make($validatedData['password']), // Password ko hash karna zaroori hai
        ]);

        // (Optional) Agar Spatie role-permission package use kar rahe hain:
        // Request se aaye role ko assign karein, ya default 'User' role de sakte hain.
        $user->assignRole($validatedData['role']);

        // 3. API Token Generate Karein (Sanctum)
        $token = $user->createToken('auth_token')->plainTextToken;

        // 4. JSON Response Return Karein
        return response()->json([
            'success' => true,
            'message' => 'User registered successfully!',
            'data' => $user,
            'token' => $token
        ], 201);
    }

    // logout
    public function logout(Request $request)
    {
        // Authenticated user ke current token ko revoke karein
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'User logged out successfully!'
        ], 200);
    }
    // refresh token
    public function refreshToken(Request $request)
    {
        // 1. Current request mein use ho rahe token ko delete karein
        $request->user()->currentAccessToken()->delete();

        // 2. Naya token generate karein
        $newToken = $request->user()->createToken('auth_token')->plainTextToken;

        // 3. Response return karein
        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully!',
            'token' => $newToken
        ], 200);
    }
}