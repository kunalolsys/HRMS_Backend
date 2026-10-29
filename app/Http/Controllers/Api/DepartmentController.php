<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $departments = Department::with(['creator:id,name', 'updater:id,name'])->paginate(20);
            return response()->json([
                'success' => true,
                'message' => 'Departments retrieved successfully.',
                'data' => $departments
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving departments.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('departments', 'code')],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $validated['created_by'] = Auth::id();

            $department = Department::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Department created successfully.',
                'data' => $department
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create department.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $department = Department::with(['creator:id,name', 'updater:id,name'])->findOrFail($id);
            return response()->json([
                'success' => true,
                'message' => 'Department retrieved successfully.',
                'data' => $department
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Department not found.'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving the department.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $department = Department::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Department not found.'], 404);
        }

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('departments', 'code')->ignore($department->id)],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $validated['updated_by'] = Auth::id();

            $department->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Department updated successfully.',
                'data' => $department->load(['creator:id,name', 'updater:id,name'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update department.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $department = Department::findOrFail($id);
            if ($department->is_delete) {
                return response()->json(['success' => false, 'message' => 'Department not found.'], 404);
            }

            if (Schema::hasColumn('employees', 'department_id') && Employee::where('department_id', $department->id)->where('is_delete', false)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This department is linked to one or more employees. Please reassign or unlink them first, then delete the department.',
                ], 409);
            }

            $department->update([
                'is_delete'  => true,
                'deleted_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Department deleted successfully.'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Department not found.'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete department.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}