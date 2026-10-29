<?php

namespace App\Http\Controllers\Api;

use App\Exports\DesignationExport;
use App\Http\Controllers\Controller;
use App\Imports\DesignationImport;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\NoticePeriod;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DesignationController extends Controller
{
    function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:designation-view|designation-create|designation-edit|designation-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:designation-create', ['only' => ['store']]);
        $this->middleware('permission:designation-edit', ['only' => ['update']]);
        $this->middleware('permission:designation-delete', ['only' => ['destroy']]);
        $this->middleware('permission:designation-restore', ['only' => ['restore']]);
        $this->middleware('permission:designation-export', ['only' => ['export']]);
        $this->middleware('permission:designation-import', ['only' => ['import', 'importTemplate', 'importErrors', 'importStructure']]);
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $page   = $request->query('page', 1);
            $limit  = $request->input('limit', 10);
            $search = mb_strtolower(trim((string) $request->query('search', '')));
            $status = strtolower(trim((string) $request->query('status', '')));
            $trashed = strtolower(trim((string) $request->query('trashed', '')));
            $currentUser = Auth::user();
            $canViewTrashed = $currentUser
                && method_exists($currentUser, 'hasAnyRole')
                && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
            $showTrashed = $canViewTrashed
                && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');
            //$status = ($status == 'active') ? 1 : (($status == 'inactive') ? 0 : null);


            $query = Designation::where('is_delete', $showTrashed)
                ->with(['creator:id,name', 'updater:id,name']);
            if($request->filled('search'))
                $query = $query->where(function ($q) use ($search)
                {
                    $q->whereRaw('LOWER(code) LIKE ?', ["%{$search}%"])
                      ->orwhereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                      ->orwhereRaw('LOWER(grade) LIKE ?', ["%{$search}%"]);                      
                });
                $query = $query->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                });
    
            $query = $query->orderBy('id', 'DESC');
            $designations = $query
                ->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Designations retrieved successfully.',
                'data' => $designations,
                'pagination' => [
                    'current_page' => $designations->currentPage(),
                    'per_page'     => $designations->perPage(),
                    'total'        => $designations->total(),
                    'last_page'    => $designations->lastPage(),
                    'from'         => $designations->firstItem(),
                    'to'           => $designations->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving designations.',
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
            'code' => ['required', 'string', 'max:255'],
            'name' => 'required|string|max:255',
            'grade' => 'nullable|string|max:255',
            'pay_band_min' => 'nullable|numeric|min:0',
            'pay_band_max' => 'nullable|numeric|gte:pay_band_min',
            'notice_period_confirmed' => 'nullable|integer|min:0',
            'notice_period_probation' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $this->validateDesignationDuplicates($validator, $request->only(['code', 'name']));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $validatedData = $validator->validated();
            if (isset($validatedData['name'])) {
                $validatedData['name'] = mb_strtolower($validatedData['name']);
            }

            $validatedData['created_by'] = Auth::id();

            $designation = Designation::create($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Designation created successfully.',
                'data' => $designation
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the designation.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Designation $designation)
    {
        if ($designation->is_delete) {
            return response()->json(['success' => false, 'message' => 'Designation not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Designation retrieved successfully.',
            'data' => $designation->load(['creator:id,name', 'updater:id,name'])
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Designation $designation)
    {
        if ($designation->is_delete) {
            return response()->json(['success' => false, 'message' => 'Designation not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:255'],
            'name' => 'required|string|max:255',
            'grade' => 'nullable|string|max:255',
            'pay_band_min' => 'nullable|numeric|min:0',
            'pay_band_max' => 'nullable|numeric|gte:pay_band_min',
            'notice_period_confirmed' => 'nullable|integer|min:0',
            'notice_period_probation' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $this->validateDesignationDuplicates(
            $validator,
            $request->only(['code', 'name']),
            $designation->id
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $validatedData = $validator->validated();
            if (isset($validatedData['name'])) {
                $validatedData['name'] = mb_strtolower($validatedData['name']);
            }
            $validatedData['updated_by'] = Auth::id();

            $designation->update($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Designation updated successfully.',
                'data' => $designation
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the designation.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Designation $designation)
    {
        if ($designation->is_delete) {
            return response()->json(['success' => false, 'message' => 'Designation not found.'], 404);
        }

        try {
            if (Employee::where('designation_id', $designation->id)->where('is_delete', false)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This designation is linked to one or more employees. Please reassign or unlink them first, then delete the designation.',
                ], 409);
            }

            if (NoticePeriod::where('designation_id', $designation->id)->where('is_delete', false)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This designation is linked to one or more notice period policies. Please delete or unlink them first, then delete the designation.',
                ], 409);
            }

            if ($designation->shiftLocationDesignations()->whereHas('shift', fn ($q) => $q->where('is_delete', false))->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This designation is linked to one or more active shifts. Please unlink them first, then delete the designation.',
                ], 409);
            }

            $designation->update([
                'is_delete' => true,
                'deleted_by' => Auth::id(),
                'deleted_at' => now()->timestamp, // Using timestamp as per migration's integer type
            ]);

            return response()->json(['success' => true, 'message' => 'Designation deleted successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the designation.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted designation.
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
                'message' => 'Unauthorized. Only Super Admin or Admin can restore designations.',
            ], 403);
        }

        $designation = Designation::where('is_delete', true)->find($id);

        if (!$designation) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted designation not found.',
            ], 404);
        }

        foreach (['code' => 'designation code', 'name' => 'designation name'] as $column => $label) {
            $value = $designation->{$column};
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $duplicate = Designation::where('is_delete', false)
                ->where('id', '!=', $designation->id)
                ->whereRaw("LOWER({$column}) = ?", [mb_strtolower(trim($value))])
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot restore. A designation with this {$label} already exists.",
                ], 409);
            }
        }

        $designation->update([
            'is_delete' => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active' => true,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Designation restored successfully.',
            'data' => $designation,
        ], 200);
    }

    /**
     * Import designations from a CSV file.
     */
    public function import(Request $request)
    {
        $templateUrl = route('designations.import.template');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $designationImport = new DesignationImport();

        try {
            $imported = $designationImport->import($request->file('file'));

            if ($designationImport->hasErrors()) {
                $errorFile = $designationImport->storeErrors();

                return response()->json([
                    'success' => false,
                    'message' => 'Some designations could not be imported.',
                    'imported' => $imported,
                    'failed' => count($designationImport->errors()),
                    'errors' => $designationImport->errors(),
                    'errors_url' => route('designations.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Designations imported successfully.',
                'imported' => $imported,
                'failed' => 0,
                'errors' => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import designations.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Export all designations as a CSV file.
     */
    public function export(Request $request)
    {
        return (new DesignationExport())->download($request);
    }

    /**
     * Download the CSV template used by the designation import endpoint.
     */
    public function importTemplate()
    {
        return (new DesignationImport())->downloadTemplate();
    }

    /**
     * Download the CSV containing rejected designation import rows.
     */
    public function importErrors(string $filename)
    {
        $path = 'designation-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'designation-import-errors.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    private function validateDesignationDuplicates(
        ValidatorContract $validator,
        array $values,
        ?int $ignoreId = null
    ): void {
        $labels = [
            'code' => 'designation code',
            'name' => 'designation name',
        ];

        $validator->after(function ($validator) use ($values, $ignoreId, $labels): void {
            foreach ($labels as $column => $label) {
                $value = $values[$column] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }

                $existing = Designation::whereRaw("LOWER({$column}) = ?", [mb_strtolower(trim($value))])
                    ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                    ->orderBy('is_delete', 'asc')
                    ->orderByDesc('id')
                    ->first();

                if (!$existing) {
                    continue;
                }

                if ($existing->is_delete) {
                    $message = "The {$label} already exists in deleted stage.";
                } elseif (!$existing->is_active) {
                    $message = "The {$label} already exists in deactivated stage.";
                } else {
                    $message = "The {$label} has already been taken.";
                }

                $validator->errors()->add($column, $message);
            }
        });
    }

    private function validationErrorResponse(ValidatorContract $validator)
    {
        $errors = $validator->errors()->toArray();
        $isDuplicate = collect($errors)->flatten()->contains(function ($message): bool {
            $message = strtolower($message);

            return str_contains($message, 'already been taken')
                || str_contains($message, 'already exists in deleted stage')
                || str_contains($message, 'already exists in deactivated stage');
        });

        return response()->json([
            'success' => false,
            'message' => $isDuplicate ? 'Designation record already exists.' : 'Validation failed.',
            'errors' => $errors,
        ], $isDuplicate ? 409 : 422);
    }
}