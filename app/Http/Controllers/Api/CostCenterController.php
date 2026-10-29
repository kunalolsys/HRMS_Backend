<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\CostCenterExport;
use App\Imports\CostCenterImport;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Location;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Validator as ValidatorContract;

class CostCenterController extends Controller
{

    function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:cost_center-view|cost_center-create|cost_center-edit|cost_center-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:cost_center-create', ['only' => ['store', 'import']]);
        $this->middleware('permission:cost_center-edit', ['only' => ['update']]);
        $this->middleware('permission:cost_center-delete', ['only' => ['destroy']]);
        $this->middleware('permission:cost_center-restore', ['only' => ['restore']]);
        $this->middleware('permission:cost_center-export', ['only' => ['export']]);
        $this->middleware('permission:cost_center-import', ['only' => ['import', 'importTemplate', 'importErrors', 'importStructure']]);
    }

    public function import(Request $request)
    {
        $templateUrl = route('cost-centers.import.template');
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $costCenterImport = new CostCenterImport();

        try {
            $imported = $costCenterImport->import($request->file('file'));

            if ($costCenterImport->hasErrors()) {
                $errorFile = $costCenterImport->storeErrors();

                return response()->json([
                    'success' => false,
                    'message' => 'Some cost centers could not be imported.',
                    'imported' => $imported,
                    'failed' => count($costCenterImport->errors()),
                    'errors' => $costCenterImport->errors(),
                    'errors_url' => route('cost-centers.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cost centers imported successfully.',
                'imported' => $imported,
                'failed' => 0,
                'errors' => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import cost centers.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request)
    {
        return (new CostCenterExport())->download($request);
    }

    public function importTemplate()
    {
        return (new CostCenterImport())->downloadTemplate();
    }

    public function importErrors(string $filename)
    {
        $path = 'cost-center-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'cost-center-import-errors.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    public function importStructure()
    {
        return response()->json([
            'success' => true,
            'message' => 'Cost center import structure retrieved successfully.',
            'data' => [
                'headers' => ['code', 'name', 'is_active'],
                'columns' => [
                    ['name' => 'code', 'type' => 'text', 'required' => true],
                    ['name' => 'name', 'type' => 'text', 'required' => true],
                    [
                        'name' => 'is_active',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Optional. Defaults to true. Accepted values: true, false, yes, no, active, inactive, 1, 0.',
                    ],
                ],
                'template_url' => route('cost-centers.import.template'),
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

            $costCenters = CostCenter::with(['creator:id,name', 'updater:id,name'])
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->whereRaw('LOWER(code) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->orderByDesc('id')
                ->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Cost Centers retrieved successfully.',
                'data' => $costCenters->items(),
                'pagination' => [
                    'current_page' => $costCenters->currentPage(),
                    'per_page' => $costCenters->perPage(),
                    'total' => $costCenters->total(),
                    'last_page' => $costCenters->lastPage(),
                    'from' => $costCenters->firstItem(),
                    'to' => $costCenters->lastItem(),
                ],
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cost center code already exists.',
                    'error' => 'Duplicate cost center record.',
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create cost center.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving cost centers.',
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
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->validateCodeAndName($validator, $request->input('code'), $request->input('name'));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validated = $validator->validated();

        try {
            $validated['created_by'] = Auth::id();
            $costCenter = CostCenter::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Cost Center created successfully.',
                'data' => $costCenter
            ], 201);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cost center code already exists.',
                    'error' => 'Duplicate cost center record.',
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to update cost center.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create cost center.',
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
            $costCenter = CostCenter::with(['creator:id,name', 'updater:id,name'])
                ->where('is_delete', false)
                ->findOrFail($id);
            return response()->json([
                'success' => true,
                'message' => 'Cost Center retrieved successfully.',
                'data' => $costCenter
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Cost Center not found.'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving the cost center.',
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
            $costCenter = CostCenter::where('is_delete', false)->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Cost Center not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => ['sometimes', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->validateCodeAndName(
            $validator,
            $request->input('code'),
            $request->input('name'),
            (int) $costCenter->id
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validated = $validator->validated();
        try {
            $validated['updated_by'] = Auth::id();

            $costCenter->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Cost Center updated successfully.',
                'data' => $costCenter->load(['creator:id,name', 'updater:id,name'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update cost center.',
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
            $costCenter = CostCenter::where('is_delete', false)->findOrFail($id);

            if (Location::where('cost_center_id', $costCenter->id)->where('is_delete', false)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This cost center is linked to one or more locations. Please unlink them first, then delete the cost center.',
                ], 409);
            }

            $isLinkedToEmployee = (Schema::hasColumn('employees', 'cost_center_id') && Employee::where('cost_center_id', $costCenter->id)->where('is_delete', false)->exists())
                || Employee::whereHas('location', fn ($q) => $q->where('cost_center_id', $costCenter->id))->where('is_delete', false)->exists();

            if ($isLinkedToEmployee) {
                return response()->json([
                    'success' => false,
                    'message' => 'This cost center is linked to one or more employees. Please reassign or unlink them first, then delete the cost center.',
                ], 409);
            }

            $costCenter->update([
                'is_delete' => true,
                'deleted_by' => Auth::id(),
                'deleted_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cost Center deleted successfully.'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Cost Center not found.'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete cost center.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted cost center.
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
                'message' => 'Unauthorized. Only Super Admin or Admin can restore cost centers.',
            ], 403);
        }

        $costCenter = CostCenter::where('is_delete', true)->find($id);

        if (!$costCenter) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted cost center not found.',
            ], 404);
        }

        $conflicts = [];
        foreach (['code', 'name'] as $column) {
            $value = trim((string) $costCenter->{$column});
            if ($value !== '' && CostCenter::where('is_delete', false)
                ->whereRaw("LOWER({$column}) = ?", [mb_strtolower($value)])
                ->where('id', '!=', $costCenter->id)
                ->exists()) {
                $conflicts[] = $column;
            }
        }

        if ($conflicts !== []) {
            $conflictLabels = implode(' and ', $conflicts);

            return response()->json([
                'success' => false,
                'message' => "Cannot restore. A cost center with this {$conflictLabels} already exists.",
                'errors' => array_fill_keys($conflicts, 'A non-deleted cost center already uses this value.'),
            ], 409);
        }

        $costCenter->update([
            'is_delete' => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active' => true,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cost center restored successfully.',
            'data' => $costCenter->load(['creator:id,name', 'updater:id,name']),
        ], 200);
    }

    private function validateCodeAndName(
        ValidatorContract $validator,
        ?string $code,
        ?string $name,
        ?int $ignoreId = null
    ): void {
        $validator->after(function ($validator) use ($code, $name, $ignoreId): void {
            foreach (['code' => $code, 'name' => $name] as $column => $value) {
                if ($value === null || trim($value) === '') {
                    continue;
                }

                $existing = CostCenter::whereRaw("LOWER({$column}) = ?", [mb_strtolower(trim($value))])
                    ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                    ->orderBy('is_delete', 'asc')
                    ->orderBy('is_active', 'desc')
                    ->orderByDesc('id')
                    ->first();

                if (!$existing) {
                    continue;
                }

                if ($existing->is_delete) {
                    $message = "A cost center {$column} found duplicated in deleted stage.";
                } elseif (!$existing->is_active) {
                    $message = "A cost center {$column} found duplicated in deactivated stage.";
                } else {
                    $message = "A cost center {$column} already exists.";
                }

                $validator->errors()->add($column, $message);
            }
        });
    }

    private function validationErrorResponse(ValidatorContract $validator)
    {
        $errors = $validator->errors()->toArray();
        $isDuplicate = fn (array $fieldErrors): bool => collect($fieldErrors)->contains(function ($message): bool {
            $message = strtolower($message);

            return str_contains($message, 'already exists')
                || str_contains($message, 'already been taken')
                || str_contains($message, 'duplicated in deleted stage')
                || str_contains($message, 'duplicated in deactivated stage');
        });
        $codeDuplicate = $isDuplicate($errors['code'] ?? []);
        $nameDuplicate = $isDuplicate($errors['name'] ?? []);

        return response()->json([
            'success' => false,
            'message' => $nameDuplicate
                ? 'Cost center name already exists.'
                : ($codeDuplicate ? 'Cost center code already exists.' : 'Validation failed.'),
            'errors' => $errors,
        ], ($codeDuplicate || $nameDuplicate) ? 409 : 422);
    }

    private function isDuplicateException(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }
}