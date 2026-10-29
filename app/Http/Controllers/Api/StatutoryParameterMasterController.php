<?php

namespace App\Http\Controllers\Api;

use App\Exports\StatutoryParameterMasterExport;
use App\Http\Controllers\Controller;
use App\Imports\StatutoryParameterMasterImport;
use App\Models\StatutoryParameterMaster;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class StatutoryParameterMasterController extends Controller
{
    private const DEFAULT_EFFECTIVE_TO = '2099-12-31';

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:statutory_parameter_master-view|statutory-parameter-master-create|statutory_parameter_master-edit|statutory_parameter_master-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:statutory_parameter_master-create', ['only' => ['store']]);
        $this->middleware('permission:statutory_parameter_master-edit', ['only' => ['update']]);
        $this->middleware('permission:statutory_parameter_master-delete', ['only' => ['destroy']]);
        $this->middleware('permission:statutory_parameter_master-restore', ['only' => ['restore']]);
        $this->middleware('permission:statutory_parameter_master-export', ['only' => ['export']]);
        $this->middleware('permission:statutory_parameter_master-import', ['only' => ['import', 'importTemplate', 'importErrors', 'importStructure']]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
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

            $parameters = StatutoryParameterMaster::with(['creator:id,name', 'updater:id,name'])
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->whereRaw('LOWER(rule_code) LIKE ?', ["%{$search}%"])
                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->orderByDesc('id')
                ->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success'    => true,
                'message'    => 'Statutory parameters retrieved successfully.',
                'data'       => $parameters->items(),
                'pagination' => [
                    'current_page' => $parameters->currentPage(),
                    'per_page'     => $parameters->perPage(),
                    'total'        => $parameters->total(),
                    'last_page'    => $parameters->lastPage(),
                    'from'         => $parameters->firstItem(),
                    'to'           => $parameters->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving statutory parameters.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->normalizeEffectiveTo($request, true);
        $validator = Validator::make($request->all(), $this->rules());
        $ruleCode = $request->input('rule_code');
        $this->validateRuleCode($validator, is_string($ruleCode) ? $ruleCode : null);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $validatedData = $validator->validated();
            $validatedData['created_by'] = Auth::id();
            $validatedData['is_active']  = $validatedData['is_active'] ?? true;
            $validatedData['is_delete']  = false;

            $parameter = StatutoryParameterMaster::create($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Statutory parameter created successfully.',
                'data'    => $parameter->load(['creator:id,name', 'updater:id,name']),
            ], 201);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Statutory rule code already exists.',
                    'error'   => 'Duplicate rule code.',
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create statutory parameter.',
                'error'   => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create statutory parameter.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $parameter = StatutoryParameterMaster::with(['creator:id,name', 'updater:id,name'])
                ->where('is_delete', false)
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Statutory parameter retrieved successfully.',
                'data'    => $parameter,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Statutory parameter not found.'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $parameter = StatutoryParameterMaster::where('is_delete', false)->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Statutory parameter not found.'], 404);
        }

        $this->normalizeEffectiveTo($request);
        $validator = Validator::make($request->all(), $this->rules());
        $ruleCode = $request->input('rule_code');
        $this->validateRuleCode($validator, is_string($ruleCode) ? $ruleCode : null, $parameter->id);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $validatedData = $validator->validated();
            $validatedData['updated_by'] = Auth::id();

            $parameter->update($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Statutory parameter updated successfully.',
                'data'    => $parameter->load(['creator:id,name', 'updater:id,name']),
            ], 200);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Statutory rule code already exists.',
                    'error'   => 'Duplicate rule code.',
                ], 409);
            }

            return response()->json(['success' => false, 'message' => 'Failed to update statutory parameter.', 'error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update statutory parameter.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $parameter = StatutoryParameterMaster::where('is_delete', false)->findOrFail($id);
            $parameter->update([
                'is_delete'  => true,
                'deleted_by' => Auth::id(),
                'deleted_at' => now(),
            ]);

            return response()->json(['success' => true, 'message' => 'Statutory parameter deleted successfully.'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Statutory parameter not found.'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete statutory parameter: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Restore a soft-deleted statutory parameter.
     */
    public function restore(int $id): JsonResponse
    {
        $currentUser = Auth::user();
        $canRestore = $currentUser
            && method_exists($currentUser, 'hasAnyRole')
            && $currentUser->hasAnyRole(['Super Admin', 'Admin']);

        if (!$canRestore) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only Super Admin or Admin can restore statutory parameters.',
            ], 403);
        }

        $parameter = StatutoryParameterMaster::where('is_delete', true)->find($id);

        if (!$parameter) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted statutory parameter not found.',
            ], 404);
        }

        $duplicateRuleCode = StatutoryParameterMaster::where('is_delete', false)
            ->whereRaw('LOWER(rule_code) = ?', [mb_strtolower(trim($parameter->rule_code))])
            ->where('id', '!=', $parameter->id)
            ->exists();

        if ($duplicateRuleCode) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. A statutory parameter with this rule code already exists.',
            ], 409);
        }

        $parameter->update([
            'is_delete' => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active' => true,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Statutory parameter restored successfully.',
            'data' => $parameter->load(['creator:id,name', 'updater:id,name']),
        ], 200);
    }

    /**
     * Import statutory parameter masters from a CSV file.
     */
    public function import(Request $request): JsonResponse
    {
        $templateUrl = route('statutory-parameter-masters.import.template');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $import = new StatutoryParameterMasterImport();

        try {
            $imported = $import->import($request->file('file'));

            if ($import->hasErrors()) {
                $errorFile = $import->storeErrors();

                return response()->json([
                    'success'      => false,
                    'message'      => 'Some statutory parameters could not be imported.',
                    'imported'     => $imported,
                    'failed'       => count($import->errors()),
                    'errors'       => $import->errors(),
                    'errors_url'   => route('statutory-parameter-masters.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success'      => true,
                'message'      => 'Statutory parameters imported successfully.',
                'imported'     => $imported,
                'failed'       => 0,
                'errors'       => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import statutory parameters.',
                'error'   => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Export all statutory parameter records as a CSV file.
     */
    public function export(Request $request)
    {
        return (new StatutoryParameterMasterExport())->download($request);
    }

    /**
     * Download template CSV for statutory parameters.
     */
    public function importTemplate()
    {
        return (new StatutoryParameterMasterImport())->downloadTemplate();
    }

    /**
     * Download CSV containing errors from a previous import.
     */
    public function importErrors(string $filename)
    {
        $path = 'statutory-parameter-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'statutory-parameter-errors.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    /**
     * Return the schema/structure expected by the import CSV.
     */
    public function importStructure(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Statutory parameter import structure retrieved successfully.',
            'data'    => [
                'headers' => [
                    'rule_code', 'description', 'pf_employee_rate', 'pf_employer_rate',
                    'pf_wage_ceiling', 'pf_eps_rate', 'pf_eps_max_cap', 'pf_start_month',
                    'esi_employee_rate', 'esi_employer_rate', 'esi_gross_ceiling', 'esi_start_month',
                    'total_deduction_cap_pct', 'effective_from', 'effective_to', 'is_active',
                ],
                'columns' => [
                    ['name' => 'rule_code', 'type' => 'text', 'required' => true, 'description' => 'Unique rule code.'],
                    ['name' => 'description', 'type' => 'text', 'required' => false, 'description' => 'Rule description.'],
                    ['name' => 'pf_employee_rate', 'type' => 'numeric', 'required' => true, 'description' => 'Employee PF rate in %.'],
                    ['name' => 'pf_employer_rate', 'type' => 'numeric', 'required' => true, 'description' => 'Employer PF rate in %.'],
                    ['name' => 'pf_wage_ceiling', 'type' => 'numeric', 'required' => true, 'description' => 'PF wage ceiling limit.'],
                    ['name' => 'pf_eps_rate', 'type' => 'numeric', 'required' => true, 'description' => 'EPS contribution rate in %.'],
                    ['name' => 'pf_eps_max_cap', 'type' => 'numeric', 'required' => true, 'description' => 'EPS maximum cap.'],
                    ['name' => 'pf_start_month', 'type' => 'integer', 'required' => true, 'description' => 'PF starting month (1-12).'],
                    ['name' => 'esi_employee_rate', 'type' => 'numeric', 'required' => true, 'description' => 'Employee ESI rate in %.'],
                    ['name' => 'esi_employer_rate', 'type' => 'numeric', 'required' => true, 'description' => 'Employer ESI rate in %.'],
                    ['name' => 'esi_gross_ceiling', 'type' => 'numeric', 'required' => true, 'description' => 'ESI gross ceiling limit.'],
                    ['name' => 'esi_start_month', 'type' => 'integer', 'required' => true, 'description' => 'ESI starting month (1-12).'],
                    ['name' => 'total_deduction_cap_pct', 'type' => 'numeric', 'required' => true, 'description' => 'Total deduction cap percentage.'],
                    ['name' => 'effective_from', 'type' => 'date', 'required' => true, 'description' => 'YYYY-MM-DD.'],
                    ['name' => 'effective_to', 'type' => 'date', 'required' => false, 'description' => 'YYYY-MM-DD.'],
                    ['name' => 'is_active', 'type' => 'boolean', 'required' => false, 'description' => 'Accepted values: 1, 0, true, false.'],
                ],
                'template_url' => route('statutory-parameter-masters.import.template'),
            ],
        ]);
    }

    private function rules(): array
    {
        return [
            'rule_code'               => ['required', 'string', 'max:50'],
            'description'             => ['nullable', 'string'],
            'pf_employee_rate'        => ['required', 'numeric', 'min:0', 'max:100'],
            'pf_employer_rate'        => ['required', 'numeric', 'min:0', 'max:100'],
            'pf_wage_ceiling'         => ['required', 'numeric', 'min:0'],
            'pf_eps_rate'             => ['required', 'numeric', 'min:0', 'max:100'],
            'pf_eps_max_cap'          => ['required', 'numeric', 'min:0'],
            'pf_start_month'          => ['required', 'integer', 'between:1,12'],
            'esi_employee_rate'       => ['required', 'numeric', 'min:0', 'max:100'],
            'esi_employer_rate'       => ['required', 'numeric', 'min:0', 'max:100'],
            'esi_gross_ceiling'       => ['required', 'numeric', 'min:0'],
            'esi_start_month'         => ['required', 'integer', 'between:1,12'],
            'total_deduction_cap_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_from'          => ['required', 'date'],
            'effective_to'            => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active'                     => ['sometimes', 'boolean'],
        ];
    }

    private function normalizeEffectiveTo(Request $request, bool $defaultWhenMissing = false): void
    {
        $input = $request->all();
        if (!$defaultWhenMissing && !array_key_exists('effective_to', $input)) {
            return;
        }

        $effectiveTo = $input['effective_to'] ?? null;
        if ($effectiveTo === null || (is_string($effectiveTo) && trim($effectiveTo) === '')) {
            $request->merge(['effective_to' => self::DEFAULT_EFFECTIVE_TO]);
        }
    }

    private function validateRuleCode(ValidatorContract $validator, ?string $ruleCode, ?int $ignoreId = null): void
    {
        if ($ruleCode === null || trim($ruleCode) === '') {
            return;
        }

        $validator->after(function ($validator) use ($ruleCode, $ignoreId): void {
            $existing = StatutoryParameterMaster::whereRaw('LOWER(rule_code) = ?', [mb_strtolower(trim($ruleCode))])
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->orderBy('is_delete', 'asc')
                ->orderByDesc('id')
                ->first();

            if (!$existing) {
                return;
            }

            if ($existing->is_delete) {
                $message = 'The rule code already exists in deleted stage.';
            } elseif (!$existing->is_active) {
                $message = 'The rule code already exists in deactivated stage.';
            } else {
                $message = 'The rule code has already been taken.';
            }

            $validator->errors()->add('rule_code', $message);
        });
    }

    private function validationErrorResponse(ValidatorContract $validator): JsonResponse
    {
        $errors = $validator->errors()->toArray();
        $isDuplicate = collect($errors)->flatten()->contains(fn ($msg) => str_contains(strtolower($msg), 'already been taken') || str_contains(strtolower($msg), 'already exists'));

        return response()->json([
            'success' => false,
            'message' => $isDuplicate ? 'Statutory rule code already exists.' : 'Validation failed.',
            'errors'  => $errors,
        ], $isDuplicate ? 409 : 422);
    }

    private function isDuplicateException(QueryException $exception): bool
    {
        return $exception->getCode() === '23000' || str_contains(strtolower($exception->getMessage()), 'duplicate');
    } 
}