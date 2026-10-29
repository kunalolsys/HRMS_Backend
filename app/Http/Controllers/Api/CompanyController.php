<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\CompanyExport;
use App\Imports\CompanyImport;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorContract;

class CompanyController extends Controller
{

    function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:company-view|company-create|company-edit|company-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:company-create', ['only' => ['store']]);
        $this->middleware('permission:company-edit', ['only' => ['update']]);
        $this->middleware('permission:company-delete', ['only' => ['destroy']]);
        $this->middleware('permission:company-restore', ['only' => ['restore']]);
        $this->middleware('permission:company-export', ['only' => ['export']]);
        $this->middleware('permission:company-import', ['only' => ['import', 'importTemplate', 'importErrors', 'importStructure']]);
    }

    public function import(Request $request)
    {
        $templateUrl = route('companies.import.template');
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $companyImport = new CompanyImport();

        try {
            $imported = $companyImport->import($request->file('file'));

            if ($companyImport->hasErrors()) {
                $errorFile = $companyImport->storeErrors();

                return response()->json([
                    'success' => false,
                    'message' => 'Some companies could not be imported. Download the error sheet for row-specific messages.',
                    'imported' => $imported,
                    'failed' => count($companyImport->errors()),
                    'errors' => $companyImport->errors(),
                    'errors_url' => route('companies.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Companies imported successfully.',
                'imported' => $imported,
                'failed' => 0,
                'errors' => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import companies.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request)
    {
        return (new CompanyExport())->download($request);
    }

    public function importTemplate()
    {
        return (new CompanyImport())->downloadTemplate();
    }

    public function importErrors(string $filename)
    {
        $path = 'company-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'company-import-errors.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    public function importStructure()
    {
        return response()->json([
            'success' => true,
            'message' => 'Company import structure retrieved successfully.',
            'data' => [
                'headers' => [
                    'company_code', 'company_name', 'company_type', 'pan_number', 'tan_number',
                    'gstin_number', 'pf_number', 'esi_number', 'lwf_number', 'registered_address', 'is_active',
                ],
                'columns' => [
                    ['name' => 'company_code', 'type' => 'text', 'required' => true],
                    ['name' => 'company_name', 'type' => 'text', 'required' => true],
                    ['name' => 'company_type', 'type' => 'text', 'required' => false],
                    ['name' => 'pan_number', 'type' => 'text', 'required' => false],
                    ['name' => 'tan_number', 'type' => 'text', 'required' => false],
                    ['name' => 'gstin_number', 'type' => 'text', 'required' => false],
                    ['name' => 'pf_number', 'type' => 'text', 'required' => false],
                    ['name' => 'esi_number', 'type' => 'text', 'required' => false],
                    ['name' => 'lwf_number', 'type' => 'text', 'required' => false],
                    ['name' => 'registered_address', 'type' => 'text', 'required' => false],
                    [
                        'name' => 'is_active',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Optional. Defaults to true. Accepted values: true, false, yes, no, active, inactive, 1, 0.',
                    ],
                ],
                'template_url' => route('companies.import.template'),
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

            $companies = Company::withTrashed()
                ->with(['creator:id,name', 'updater:id,name'])
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        foreach (['company_code', 'company_name'] as $column) {
                            $query->orWhereRaw("LOWER({$column}) LIKE ?", ["%{$search}%"]);
                        }
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
                'message' => 'Companies retrieved successfully.',
                'data' => $companies->items(),
                'pagination' => [
                    'current_page' => $companies->currentPage(),
                    'per_page' => $companies->perPage(),
                    'total' => $companies->total(),
                    'last_page' => $companies->lastPage(),
                    'from' => $companies->firstItem(),
                    'to' => $companies->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving companies.',
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
            'company_code' => ['required', 'string', 'max:255', Rule::unique('companies', 'company_code')->where(fn ($query) => $query->where('is_delete', false))],
            'company_name' => 'required|string|max:255',
            'company_type' => 'nullable|string|max:255',
            'pan_number' => [
                'nullable',
                'string',
                'max:10',
                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/i',
                Rule::unique('companies', 'pan_number')->where(fn ($query) => $query->where('is_delete', false)),
            ],
            'tan_number' => ['nullable', 'string', 'max:10', Rule::unique('companies', 'tan_number')->where(fn ($query) => $query->where('is_delete', false))],
            'gstin_number' => [
                'nullable',
                'string',
                'max:15',
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/i',
                Rule::unique('companies', 'gstin_number')->where(fn ($query) => $query->where('is_delete', false)),
            ],
            'pf_number' => ['nullable', 'string', 'max:255', Rule::unique('companies', 'pf_number')->where(fn ($query) => $query->where('is_delete', false))],
            'esi_number' => ['nullable', 'string', 'max:255', Rule::unique('companies', 'esi_number')->where(fn ($query) => $query->where('is_delete', false))],
            'lwf_number' => ['nullable', 'string', 'max:255', Rule::unique('companies', 'lwf_number')->where(fn ($query) => $query->where('is_delete', false))],
            'registered_address' => 'nullable|string',
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'pan_number.regex' => 'Please enter a valid PAN number format (e.g. ABCDE1234F).',
            'gstin_number.regex' => 'Please enter a valid 15-character GSTIN format (e.g. 07AAAAA0000A1Z5).',
        ]);
        $this->validateCompanyDuplicates($validator, $request->only([
            'company_name', 'company_code', 'pan_number', 'tan_number',
            'gstin_number', 'pf_number', 'esi_number', 'lwf_number',
        ]));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validatedData = $validator->validated();

        try {
            $validatedData['created_by'] = Auth::id();
            $company = Company::create($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Company created successfully.',
                'data' => $company
            ], 201);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json(['success' => false, 'message' => 'Company record already exists.', 'error' => 'Duplicate company record.'], 409);
            }
            return response()->json(['success' => false, 'message' => 'Failed to create company.', 'error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving companies.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Company $company)
    {
        if ($company->is_delete) {
            return response()->json(['success' => false, 'message' => 'Company not found.'], 404);
        }

        try {
            return response()->json([
                'success' => true,
                'message' => 'Company retrieved successfully.',
                'data' => $company
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving the company.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Company $company)
    {
        if ($company->is_delete) {
            return response()->json(['success' => false, 'message' => 'Company not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'company_code' => ['required', 'string', 'max:255', Rule::unique('companies', 'company_code')->ignore($company->id, 'id')->where(fn ($query) => $query->where('is_delete', false))],
            'company_name' => 'required|string|max:255',
            'company_type' => 'nullable|string|max:255',
            'pan_number' => [
                'nullable',
                'string',
                'max:10',
                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/i',
                Rule::unique('companies', 'pan_number')->ignore($company->id, 'id')->where(fn ($query) => $query->where('is_delete', false)),
            ],
            'tan_number' => ['nullable', 'string', 'max:10', Rule::unique('companies', 'tan_number')->ignore($company->id, 'id')->where(fn ($query) => $query->where('is_delete', false))],
            'gstin_number' => [
                'nullable',
                'string',
                'max:15',
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/i',
                Rule::unique('companies', 'gstin_number')->ignore($company->id, 'id')->where(fn ($query) => $query->where('is_delete', false)),
            ],
            'pf_number' => ['nullable', 'string', 'max:255', Rule::unique('companies', 'pf_number')->ignore($company->id, 'id')->where(fn ($query) => $query->where('is_delete', false))],
            'esi_number' => ['nullable', 'string', 'max:255', Rule::unique('companies', 'esi_number')->ignore($company->id, 'id')->where(fn ($query) => $query->where('is_delete', false))],
            'lwf_number' => ['nullable', 'string', 'max:255', Rule::unique('companies', 'lwf_number')->ignore($company->id, 'id')->where(fn ($query) => $query->where('is_delete', false))],
            'registered_address' => 'nullable|string',
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'pan_number.regex' => 'Please enter a valid PAN number format (e.g. ABCDE1234F).',
            'gstin_number.regex' => 'Please enter a valid 15-character GSTIN format (e.g. 07AAAAA0000A1Z5).',
        ]);
        $this->validateCompanyDuplicates($validator, $request->only([
            'company_name', 'company_code', 'pan_number', 'tan_number',
            'gstin_number', 'pf_number', 'esi_number', 'lwf_number',
        ]), $company->id);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validatedData = $validator->validated();

        try {
            $validatedData['updated_by'] = Auth::id();

            $company->update($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Company updated successfully.',
                'data' => $company
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json(['success' => false, 'message' => 'Company record already exists.', 'error' => 'Duplicate company record.'], 409);
            }
            return response()->json(['success' => false, 'message' => 'Failed to update company.', 'error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update company.',
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
            $company = Company::findOrFail($id);
            if ($company->is_delete) {
                return response()->json(['success' => false, 'message' => 'Company not found.'], 404);
            }

            if (Employee::where('company_id', $company->id)->where('is_delete', false)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This company is linked to one or more employees. Please reassign or unlink them first, then delete the company.',
                ], 409);
            }

            $company->update([
                'is_delete' => true,
                'deleted_by' => Auth::id(),
                'deleted_at' => now(),
            ]);
            $company->delete();
            return response()->json([
                'success' => true,
                'message' => 'Company deleted successfully.'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Company not found.'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete company.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted company when none of its unique values are in use.
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
                'message' => 'Unauthorized. Only Super Admin or Admin can restore companies.',
            ], 403);
        }

        $company = Company::withTrashed()->where('is_delete', true)->find($id);

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted company not found.',
            ], 404);
        }

        $uniqueColumns = [
            'company_name' => 'company name',
            'company_code' => 'company code',
            'pan_number' => 'PAN number',
            'tan_number' => 'TAN number',
            'gstin_number' => 'GSTIN number',
            'pf_number' => 'PF number',
            'esi_number' => 'ESI number',
            'lwf_number' => 'LWF number',
        ];
        $conflicts = [];

        foreach ($uniqueColumns as $column => $label) {
            $value = $company->{$column};
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            if (Company::where('is_delete', false)
                ->whereRaw("LOWER({$column}) = ?", [mb_strtolower(trim((string) $value))])
                ->where('id', '!=', $company->id)
                ->exists()) {
                $conflicts[] = $label;
            }
        }

        if ($conflicts !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. A company with the same ' . implode(', ', $conflicts) . ' already exists.',
            ], 409);
        }

        $company->restore();
        $company->update([
            'is_delete' => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active' => true,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Company restored successfully.',
            'data' => $company->fresh(),
        ], 200);
    }

    private function validateCompanyDuplicates(
        ValidatorContract $validator,
        array $values,
        ?int $ignoreId = null
    ): void {
        $labels = [
            'company_name' => 'company name',
            'company_code' => 'company code',
            'pan_number' => 'PAN number',
            'tan_number' => 'TAN number',
            'gstin_number' => 'GSTIN number',
            'pf_number' => 'PF number',
            'esi_number' => 'ESI number',
            'lwf_number' => 'LWF number',
        ];

        $validator->after(function ($validator) use ($values, $ignoreId, $labels): void {
            foreach ($labels as $column => $label) {
                $value = $values[$column] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }

                $existing = Company::withTrashed()
                    ->whereRaw("LOWER({$column}) = ?", [mb_strtolower(trim($value))])
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
        $duplicate = collect($errors)->flatten()->contains(function ($message): bool {
            $message = strtolower($message);

            return str_contains($message, 'already been taken')
                || str_contains($message, 'already exists in deleted stage')
                || str_contains($message, 'already exists in deactivated stage');
        });

        return response()->json([
            'success' => false,
            'message' => $duplicate ? 'Company record already exists.' : 'Validation failed.',
            'errors' => $errors,
        ], $duplicate ? 409 : 422);
    }

    private function isDuplicateException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation');
    }
}