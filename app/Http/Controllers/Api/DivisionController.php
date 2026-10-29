<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\DivisionExport;
use App\Imports\DivisionImport;
use App\Models\BrandDivision;
use App\Models\Division;
use App\Models\Employee;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;

class DivisionController extends Controller
{

    function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:division-view|division-create|division-edit|division-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:division-create', ['only' => ['store', 'import']]);
        $this->middleware('permission:division-edit', ['only' => ['update']]);
        $this->middleware('permission:division-delete', ['only' => ['destroy']]);
        $this->middleware('permission:division-restore', ['only' => ['restore']]);
        $this->middleware('permission:division-export', ['only' => ['export']]);
        $this->middleware('permission:division-import', ['only' => ['import', 'importTemplate', 'importErrors', 'importStructure']]);
    }

    public function import(Request $request)
    {
        $templateUrl = route('divisions.import.template');
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $divisionImport = new DivisionImport();

        try {
            $imported = $divisionImport->import($request->file('file'));

            if ($divisionImport->hasErrors()) {
                $errorFile = $divisionImport->storeErrors();

                return response()->json([
                    'success' => false,
                    'message' => 'Some departments could not be imported.',
                    'imported' => $imported,
                    'failed' => count($divisionImport->errors()),
                    'errors' => $divisionImport->errors(),
                    'errors_url' => route('divisions.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Departments imported successfully.',
                'imported' => $imported,
                'failed' => 0,
                'errors' => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import departments.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request)
    {
        return (new DivisionExport())->download($request);
    }

    public function importTemplate()
    {
        return (new DivisionImport())->downloadTemplate();
    }

    public function importErrors(string $filename)
    {
        $path = 'division-import-errors/' . basename($filename);
 
        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'division-import-errors.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    public function importStructure()
    {
        return response()->json([
            'success' => true,
            'message' => 'Department import structure retrieved successfully.',
            'data' => [
                'headers' => ['name', 'is_active'],
                'columns' => [
                    ['name' => 'name', 'type' => 'text', 'required' => true],
                    [
                        'name' => 'is_active',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Optional. Defaults to true. Accepted values: true, false, yes, no, active, inactive, 1, 0.',
                    ],
                ],
                'template_url' => route('divisions.import.template'),
            ],
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $page = $request->query('page', 1);
            $limit = $request->query('limit', 10);
            $search = mb_strtolower(trim((string) $request->query('search', '')));
            $status = strtolower(trim((string) $request->query('status', '')));
            $trashed = strtolower(trim((string) $request->query('trashed', '')));
            $currentUser = Auth::user();
            $canViewTrashed = $currentUser
                && method_exists($currentUser, 'hasAnyRole')
                && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
            $showTrashed = $canViewTrashed
                && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');

            $divisions = Division::query()
                ->when($search !== '', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]))
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->orderByDesc('id')
                ->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Departments retrieved successfully.',
                'data' => $divisions->items(),
                'pagination' => [
                    'current_page' => $divisions->currentPage(),
                    'per_page' => $divisions->perPage(),
                    'total' => $divisions->total(),
                    'last_page' => $divisions->lastPage(),
                    'from' => $divisions->firstItem(),
                    'to' => $divisions->lastItem(),
                ],
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
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->validateDivisionName($validator, $request->input('name'));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validatedData = $validator->validated();

        try {
            $validatedData['created_by'] = Auth::id();
            $division = Division::create($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Department created successfully.',
                'data' => $division
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
            $division = Division::where('is_delete', false)->findOrFail($id);
            return response()->json([
                'success' => true,
                'message' => 'Department retrieved successfully.',
                'data' => $division
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
            $division = Division::where('is_delete', false)->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Department not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->validateDivisionName($validator, $request->input('name'), $division->id);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validatedData = $validator->validated();

        try {
            $validatedData['updated_by'] = Auth::id();
            $division->update($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Department updated successfully.',
                'data' => $division
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
    public function destroy(Division $division)
    {
        if ($division->is_delete) {
            return response()->json(['success' => false, 'message' => 'Department not found.'], 404);
        }

        try {
            $isLinkedToBrand = BrandDivision::where('division_id', $division->id)
                ->exists();

            if ($isLinkedToBrand) {
                return response()->json([
                    'success' => false,
                    'message' => 'This department is linked to a brand. Please unlink or remove it from the brand first, then delete the department.',
                ], 409);
            }

            if (Employee::where('division_id', $division->id)->where('is_delete', false)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This department is linked to one or more employees. Please reassign or unlink them first, then delete the department.',
                ], 409);
            }

            $division->update([
                'is_delete' => true,
                'deleted_by' => Auth::id(),
                'deleted_at' => now(),
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Department deleted successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete department.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted division.
     */
    public function restore(int $id)
    {
        $currentUser = Auth::user();
        $canRestore = $currentUser
            && method_exists($currentUser, 'hasAnyRole')
            && $currentUser->hasAnyRole(['Super Admin', 'Admin']);

        if (!$canRestore) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only Super Admin or Admin can restore departments.',
            ], 403);
        }

        $division = Division::where('is_delete', true)->find($id);

        if (!$division) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted department not found.',
            ], 404);
        }

        $duplicateName = Division::where('is_delete', false)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($division->name))])
            ->where('id', '!=', $division->id)
            ->exists();

        if ($duplicateName) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. A department with this name already exists.',
            ], 409);
        }

        $division->update([
            'is_delete' => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active' => true,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department restored successfully.',
            'data' => $division,
        ], 200);
    }

    private function validateDivisionName(ValidatorContract $validator, ?string $name, ?int $ignoreId = null): void
    {
        if ($name === null || trim($name) === '') {
            return;
        }

        $validator->after(function ($validator) use ($name, $ignoreId): void {
            $existing = Division::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->orderBy('is_delete', 'asc')
                ->orderByDesc('id')
                ->first();

            if (!$existing) {
                return;
            }

            if ($existing->is_delete) {
                $message = 'The department name already exists in deleted stage.';
            } elseif (!$existing->is_active) {
                $message = 'The department name already exists in deactivated stage.';
            } else {
                $message = 'The department name has already been taken.';
            }

            $validator->errors()->add('name', $message);
        });
    }

    private function validationErrorResponse(ValidatorContract $validator)
    {
        $errors = $validator->errors()->toArray();
        $duplicate = collect($errors)->flatten()->contains(function ($message): bool {
            $message = strtolower($message);

            return str_contains($message, 'already been taken')
                || str_contains($message, 'already exists in deleted stage')
                || str_contains($message, 'already exists in deactivated stage');
        });

        return response()->json([
            'success' => false,
            'message' => $duplicate ? 'Department name already exists.' : 'Validation failed.',
            'errors' => $errors,
        ], $duplicate ? 409 : 422);
    }
}