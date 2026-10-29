<?php

namespace App\Http\Controllers\Api;

use App\Exports\NoticePeriodExport;
use App\Http\Controllers\Controller;
use App\Imports\NoticePeriodImport;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\NoticePeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class NoticePeriodController extends Controller
{
    function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:notice_period-view|notice_period-create|notice_period-edit|notice_period-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:notice_period-create', ['only' => ['store']]);
        $this->middleware('permission:notice_period-edit', ['only' => ['update']]);
        $this->middleware('permission:notice_period-delete', ['only' => ['destroy']]);
        $this->middleware('permission:notice_period-restore', ['only' => ['restore']]);
        $this->middleware('permission:notice_period-export', ['only' => ['export']]);
        $this->middleware('permission:notice_period-import', ['only' => ['import', 'importTemplate', 'importErrors', 'importStructure']]);
    }
    /**
     * Import notice periods from a CSV file.
     */
    public function import(Request $request): JsonResponse
    {
        $templateUrl = route('notice-periods.import.template');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $import = new NoticePeriodImport();

        try {
            $imported = $import->import($request->file('file'));

            if ($import->hasErrors()) {
                $errorFile = $import->storeErrors();

                return response()->json([
                    'success'      => false,
                    'message'      => 'Some notice periods could not be imported.',
                    'imported'     => $imported,
                    'failed'       => count($import->errors()),
                    'errors'       => $import->errors(),
                    'errors_url'   => route('notice-periods.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success'      => true,
                'message'      => 'Notice periods imported successfully.',
                'imported'     => $imported,
                'failed'       => 0,
                'errors'       => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import notice periods.',
                'error'   => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Export all notice periods as a CSV file.
     */
    public function export(Request $request)
    {
        return (new NoticePeriodExport())->download($request);
    }

    /**
     * Download the CSV template for notice period import.
     */
    public function importTemplate()
    {
        return (new NoticePeriodImport())->downloadTemplate();
    }

    /**
     * Download the CSV containing rejected import rows.
     */
    public function importErrors(string $filename)
    {
        $path = 'notice-period-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'notice-periods-errors.csv',
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
            'message' => 'Notice period import structure retrieved successfully.',
            'data'    => [
                'headers' => ['designation', 'notice_period_days', 'probation_notice_period_days', 'description', 'is_active'],
                'columns' => [
                    ['name' => 'designation', 'type' => 'text', 'required' => true, 'description' => 'Existing designation code, name, or ID; designation must be active and not deleted.'],
                    ['name' => 'notice_period_days', 'type' => 'integer', 'required' => true, 'description' => 'Notice period days for confirmed staff.'],
                    ['name' => 'probation_notice_period_days', 'type' => 'integer', 'required' => false, 'description' => 'Notice period days during probation.'],
                    ['name' => 'description', 'type' => 'text', 'required' => false, 'description' => 'Rule notes or policy description.'],
                    ['name' => 'is_active', 'type' => 'boolean', 'required' => false, 'description' => 'Accepted values: 1, 0, true, false, active, inactive.'],
                ],
                'template_url' => route('notice-periods.import.template'),
            ],
        ]);
    }

    /**
     * Display a listing of notice periods.
     */
    public function index(Request $request)
    {
        
        $limit         = (int) $request->query('limit', 10);
        $page          = (int) $request->query('page', 1);
        $search        = mb_strtolower(trim((string) $request->query('search', '')));
        $status        = strtolower(trim((string) $request->query('status', '')));
        $designationId = $request->query('designation_id');
        $trashed       = strtolower(trim((string) $request->query('trashed', '')));

        $currentUser = Auth::user();
        $canViewTrashed = $currentUser
            && method_exists($currentUser, 'hasAnyRole')
            && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
        $showTrashed = $canViewTrashed
            && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');

        $query = NoticePeriod::with([
            'designation:id,code,name',
            'creator:id,name',
            'updater:id,name',
        ])
        ->where('is_delete', $showTrashed)
        ->when($designationId, function ($q) use ($designationId) {
            $q->where('designation_id', $designationId);
        })
        ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($q) use ($status) {
            $isActive = in_array($status, ['active', '1', 'true'], true);
            $q->where('is_active', $isActive);
        })
        ->when($search !== '', function ($q) use ($search) {
            $q->where(function ($sub) use ($search) {
                $sub->whereRaw('LOWER(description) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('designation', function ($dQuery) use ($search) {
                        $dQuery->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                               ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"]);
                    });
            });
        })
        ->orderBy('id', 'DESC');

        $records = $query->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'success'    => true,
            'message'    => 'Notice periods retrieved successfully.',
            'data'       => $records->items(),
            'pagination' => [
                'current_page' => $records->currentPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
                'last_page'    => $records->lastPage(),
                'from'         => $records->firstItem(),
                'to'           => $records->lastItem(),
            ],
        ], 200);
    }

    /**
     * Store a newly created notice period.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'designation_id'               => ['required', 'integer'],
            'notice_period_days'           => ['required', 'integer', 'min:0'],
            'probation_notice_period_days' => ['nullable', 'integer', 'min:0'],
            'description'                  => ['nullable', 'string', 'max:1000'],
            'is_active'                    => ['nullable', 'boolean'],
        ]);

        $this->validateDesignationAndRule($validator, (int) $request->input('designation_id'));

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $validated['created_by'] = Auth::id();
        $validated['is_active']  = $request->boolean('is_active', true);
        $validated['is_delete']  = false;

        $noticePeriod = NoticePeriod::create($validated);
        $noticePeriod->load(['designation:id,code,name', 'creator:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Notice period created successfully.',
            'data'    => $noticePeriod,
        ], 201);
    }

    /**
     * Display the specified notice period.
     */
    public function show($id)
    {
        $noticePeriod = NoticePeriod::with([
            'designation:id,code,name',
            'creator:id,name',
            'updater:id,name',
        ])
        ->where('is_delete', false)
        ->find($id);

        if (!$noticePeriod) {
            return response()->json([
                'success' => false,
                'message' => 'Notice period not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notice period details retrieved successfully.',
            'data'    => $noticePeriod,
        ], 200);
    }

    /**
     * Update the specified notice period.
     */
    public function update(Request $request, $id)
    {
        $noticePeriod = NoticePeriod::where('is_delete', false)->find($id);

        if (!$noticePeriod) {
            return response()->json([
                'success' => false,
                'message' => 'Notice period not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'designation_id'               => ['sometimes', 'required', 'integer'],
            'notice_period_days'           => ['sometimes', 'required', 'integer', 'min:0'],
            'probation_notice_period_days' => ['nullable', 'integer', 'min:0'],
            'description'                  => ['nullable', 'string', 'max:1000'],
            'is_active'                    => ['sometimes', 'boolean'],
        ]);

        if ($request->has('designation_id')) {
            $this->validateDesignationAndRule($validator, (int) $request->input('designation_id'), (int) $noticePeriod->id);
        }

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $validated['updated_by'] = Auth::id();
        $noticePeriod->update($validated);
        $noticePeriod->load(['designation:id,code,name', 'updater:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Notice period updated successfully.',
            'data'    => $noticePeriod,
        ], 200);
    }

    /**
     * Soft delete the specified notice period.
     */
    public function destroy($id)
    {
        $noticePeriod = NoticePeriod::where('is_delete', false)->find($id);

        if (!$noticePeriod) {
            return response()->json([
                'success' => false,
                'message' => 'Notice period not found.',
            ], 404);
        }

        try {
            if (Designation::where('id', $noticePeriod->designation_id)->where('is_delete', false)->where('is_active', true)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This notice period is linked to an active designation. Please deactivate or unlink the designation first, then delete the notice period.',
                ], 409);
            }

            if (Employee::where('designation_id', $noticePeriod->designation_id)->where('is_delete', false)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This notice period is linked to one or more active employees. Please reassign their designation first, then delete the notice period.',
                ], 409);
            }

            $noticePeriod->update([
                'is_delete'  => true,
                'deleted_by' => Auth::id(),
                'deleted_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Notice period deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notice period.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted notice period.
     */
    public function restore(int $id): JsonResponse
    {
        $currentUser = Auth::user();
        $canRestore  = $currentUser && method_exists($currentUser, 'hasAnyRole') && $currentUser->hasAnyRole(['Super Admin', 'Admin']);

        if (!$canRestore) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only Super Admin or Admin can restore notice periods.',
            ], 403);
        }

        $noticePeriod = NoticePeriod::where('is_delete', true)->find($id);

        if (!$noticePeriod) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted notice period not found.',
            ], 404);
        }

        $designation = Designation::find($noticePeriod->designation_id);
        if (!$designation || $designation->is_delete || !$designation->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. The associated designation is missing, deleted, or inactive.',
            ], 422);
        }

        $alreadyActive = NoticePeriod::where('designation_id', $noticePeriod->designation_id)
            ->where('is_delete', false)
            ->where('id', '!=', $id)
            ->exists();

        if ($alreadyActive) {
            return response()->json([
                'success' => false,
                'message' => 'An active notice period rule already exists for this designation.',
            ], 409);
        }

        $noticePeriod->update([
            'is_delete'  => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active'  => true,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notice period restored successfully.',
            'data'    => $noticePeriod->load(['designation:id,code,name', 'updater:id,name']),
        ], 200);
    }

    private function validateDesignationAndRule(\Illuminate\Validation\Validator $validator, int $designationId, ?int $ignoreId = null): void
    {
        $validator->after(function ($validator) use ($designationId, $ignoreId): void {
            if ($designationId <= 0) {
                return;
            }

            $designation = Designation::find($designationId);
            if (!$designation) {
                $validator->errors()->add('designation_id', 'The selected designation does not exist.');
                return;
            }
            if ($designation->is_delete) {
                $validator->errors()->add('designation_id', 'The selected designation is deleted.');
                return;
            }
            if (!$designation->is_active) {
                $validator->errors()->add('designation_id', 'The selected designation is inactive and cannot be assigned.');
                return;
            }

            $existing = NoticePeriod::where('designation_id', $designationId)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->orderBy('is_delete', 'asc') // active/non-deleted record pehle check hoga
                ->first();

            if ($existing) {
                if ($existing->is_delete) {
                    $validator->errors()->add('designation_id', 'A notice period rule found duplicated in deleted stage.');
                } elseif (!$existing->is_active) {
                    $validator->errors()->add('designation_id', 'A notice period rule found duplicated in deactivated stage.');
                } else {
                    $validator->errors()->add('designation_id', 'A notice period rule is already configured for this designation.');
                }
            }
        });
    }
}