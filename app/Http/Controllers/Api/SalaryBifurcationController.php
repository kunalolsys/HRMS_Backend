<?php

namespace App\Http\Controllers\Api;

use App\Exports\SalaryBifurcationExport;
use App\Http\Controllers\Controller;
use App\Http\Traits\GeneratesSignedImportErrorUrl;
use App\Imports\SalaryBifurcationImport;
use App\Models\SalaryBifurcation;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class SalaryBifurcationController extends Controller
{
    use GeneratesSignedImportErrorUrl;

    public function __construct()
    {
        $this->middleware('auth:sanctum', ['except' => ['importErrors']]);
        $this->middleware('permission:salary-bifurcation-view|salary-bifurcation-create|salary-bifurcation-edit|salary-bifurcation-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:salary-bifurcation-create', ['only' => ['store']]);
        $this->middleware('permission:salary-bifurcation-edit', ['only' => ['update']]);
        $this->middleware('permission:salary-bifurcation-delete', ['only' => ['destroy']]);
        $this->middleware('permission:salary-bifurcation-restore', ['only' => ['restore']]);
        $this->middleware('permission:salary-bifurcation-export', ['only' => ['export']]);
        $this->middleware('permission:salary-bifurcation-import', ['only' => ['import', 'importTemplate', 'importStructure']]);
    }

    /**
     * Import salary bifurcations from a CSV file.
     */
    public function import(Request $request): JsonResponse
    {
        $templateUrl = route('salary-bifurcations.import.template');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $import = new SalaryBifurcationImport();

        try {
            $imported = $import->import($request->file('file'));

            if ($import->hasErrors()) {
                $errorFile = $import->storeErrors();

                return response()->json([
                    'success'      => false,
                    'message'      => 'Some salary bifurcations could not be imported.',
                    'imported'     => $imported,
                    'failed'       => count($import->errors()),
                    'errors'       => $import->errors(),
                    'errors_url'   => $this->generateImportErrorsUrl('salary-bifurcations.import.errors', $errorFile),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success'      => true,
                'message'      => 'Salary bifurcations imported successfully.',
                'imported'     => $imported,
                'failed'       => 0,
                'errors'       => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import salary bifurcations.',
                'error'   => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Export salary bifurcations as Excel file.
     */
    public function export(Request $request)
    {
        return (new SalaryBifurcationExport())->download($request);
    }

    /**
     * Download template for salary bifurcation import.
     */
    public function importTemplate()
    {
        return (new SalaryBifurcationImport())->downloadTemplate();
    }

    /**
     * Download error CSV from previous import.
     */
    public function importErrors(string $filename)
    {
        $path = 'salary-bifurcation-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'salary-bifurcation-import-errors.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    /**
     * Return column structure and metadata expected for import.
     */
    public function importStructure(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Salary bifurcation import structure retrieved successfully.',
            'data'    => [
                'headers' => [
                    'actual_salary', 'books_basic', 'bonus', 'books_hra',
                    'other_allowances', 'total_salary', 'effective_start_date', 'effective_end_date', 'is_active',
                ],
                'columns' => [
                    ['name' => 'actual_salary', 'type' => 'numeric', 'required' => true, 'description' => 'Gross actual salary amount.'],
                    ['name' => 'books_basic', 'type' => 'numeric', 'required' => true, 'description' => 'Basic salary component.'],
                    ['name' => 'bonus', 'type' => 'numeric', 'required' => false, 'description' => 'Monthly bonus component.'],
                    ['name' => 'books_hra', 'type' => 'numeric', 'required' => false, 'description' => 'HRA component.'],
                    ['name' => 'other_allowances', 'type' => 'numeric', 'required' => false, 'description' => 'Other allowances component.'],
                    ['name' => 'total_salary', 'type' => 'numeric', 'required' => false, 'description' => 'Total salary (auto-calculated if blank).'],
                    ['name' => 'effective_start_date', 'type' => 'date', 'required' => true, 'description' => 'YYYY-MM-DD format.'],
                    ['name' => 'effective_end_date', 'type' => 'date', 'required' => false, 'description' => 'YYYY-MM-DD format (must be on or after effective_start_date).'],
                    ['name' => 'is_active', 'type' => 'boolean', 'required' => false, 'description' => 'Accepted values: true, false, 1, 0, active, inactive. Defaults to true.'],
                ],
                'template_url' => route('salary-bifurcations.import.template'),
            ],
        ]);
    }

    /**
     * Display a listing of the salary bifurcations.
     */
    public function index(Request $request): JsonResponse
    {
        $status = strtolower(trim((string) $request->query('status', '')));
        $trashed = strtolower(trim((string) $request->query('trashed', '')));
        $currentUser = $request->user();
        $canViewTrashed = $currentUser
            && method_exists($currentUser, 'hasAnyRole')
            && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
        $showTrashed = $canViewTrashed
            && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');

        $query = SalaryBifurcation::query()
            ->with(['createdBy:id,name', 'updatedBy:id,name'])
            ->when($showTrashed, function ($q) {
                $q->onlyTrashed();
            })
            ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($q) use ($status) {
                $isActive = in_array($status, ['active', '1', 'true'], true);
                $q->where('is_active', $isActive);
            })
            ->orderByDesc('id');

        if (!$request->query->has('page') && !$request->query->has('limit')) {
            return response()->json([
                'success' => true,
                'message' => 'Salary bifurcations retrieved successfully.',
                'data'    => $query->get(),
            ], 200);
        }

        $limit = (int) $request->query('limit', 10);
        $bifurcations = $query->paginate($limit);

        return response()->json([
            'success'    => true,
            'message'    => 'Salary bifurcations retrieved successfully.',
            'data'       => $bifurcations->items(),
            'pagination' => [
                'current_page' => $bifurcations->currentPage(),
                'per_page'     => $bifurcations->perPage(),
                'total'        => $bifurcations->total(),
                'last_page'    => $bifurcations->lastPage(),
                'from'         => $bifurcations->firstItem(),
                'to'           => $bifurcations->lastItem(),
            ],
        ], 200);
    }

    /**
     * Store a newly created salary bifurcation.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'actual_salary'        => 'required|numeric|min:0',
            'books_basic'          => 'required|numeric|min:0',
            'bonus'                => 'nullable|numeric|min:0',
            'books_hra'            => 'nullable|numeric|min:0',
            'other_allowances'     => 'nullable|numeric|min:0',
            'total_salary'         => 'nullable|numeric|min:0',
            'effective_start_date' => 'required|date',
            'effective_end_date'   => 'nullable|date|after_or_equal:effective_start_date',
            'is_active'            => 'nullable|boolean',
        ]);

        $validator->after(function ($validator) use ($request): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $actualSalary = $request->input('actual_salary');
            $booksBasic = $request->input('books_basic');
            $newStartDate = $request->input('effective_start_date');

            if ($actualSalary !== null && $booksBasic !== null && (float) $actualSalary === (float) $booksBasic) {
                $validator->errors()->add(
                    'books_basic',
                    'The actual salary and books basic values cannot be identical.'
                );
            }

            $newEndDate = $request->input('effective_end_date');
            $existingRecord = SalaryBifurcation::withTrashed()
                ->where(function ($q): void {
                    $q->where('is_active', false)
                        ->orWhere('is_deleted', true)
                        ->orWhereNotNull('deleted_at');
                })
                ->where(function ($query) use ($newStartDate, $newEndDate): void {
                    $query->whereDate('effective_start_date', $newStartDate)
                        ->orWhere(function ($q) use ($newStartDate): void {
                            $q->whereDate('effective_start_date', '<=', $newStartDate)
                                ->where(function ($sub) use ($newStartDate): void {
                                    $sub->whereNull('effective_end_date')
                                        ->orWhereDate('effective_end_date', '>=', $newStartDate);
                                });
                        });

                    if (!empty($newEndDate)) {
                        $query->orWhere(function ($q) use ($newStartDate, $newEndDate): void {
                            $q->whereDate('effective_start_date', '<=', $newEndDate)
                                ->where(function ($sub) use ($newStartDate): void {
                                    $sub->whereNull('effective_end_date')
                                        ->orWhereDate('effective_end_date', '>=', $newStartDate);
                                });
                        });
                    }
                })
                ->first();

            if ($existingRecord) {
                $dateRange = !empty($existingRecord->date_range)
                    ? $existingRecord->date_range
                    : $this->formatDateRange($existingRecord->effective_start_date, $existingRecord->effective_end_date);
                $isDeleted = $existingRecord->trashed() || (bool) $existingRecord->is_deleted;
                $stageMode = $isDeleted ? 'deleted' : 'inactive';
                $validator->errors()->add(
                    'effective_start_date',
                    "A salary bifurcation entry with date range ({$dateRange}) already exists in the system in {$stageMode} mode."
                );
                return;
            }

            $previous = SalaryBifurcation::orderByDesc('effective_start_date')
                ->orderByDesc('id')
                ->first();

            if ($previous) {
                if (empty($previous->effective_end_date)) {
                    $validator->errors()->add(
                        'effective_start_date',
                        'The previous salary bifurcation record has an empty effective end date. Please update the previous record\'s end date before adding a new entry.'
                    );
                    return;
                }

                if (Carbon::parse($newStartDate)->lte(Carbon::parse($previous->effective_end_date))) {
                    $prevEndDate = Carbon::parse($previous->effective_end_date)->format('d-m-Y');
                    $validator->errors()->add(
                        'effective_start_date',
                        "The effective start date must be greater than the previous record's effective end date ({$prevEndDate})."
                    );
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $validated['bonus'] = $validated['bonus'] ?? 0;
        $validated['books_hra'] = $validated['books_hra'] ?? 0;
        $validated['other_allowances'] = $validated['other_allowances'] ?? 0;
        $validated['total_salary'] = $validated['total_salary'] ?? ($validated['books_basic'] + $validated['bonus'] + $validated['books_hra'] + $validated['other_allowances']);
        $validated['created_by'] = $request->user()?->id;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['is_deleted'] = false;

        $bifurcation = SalaryBifurcation::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Salary bifurcation created successfully.',
            'data'    => $bifurcation->load(['createdBy:id,name', 'updatedBy:id,name']),
        ], 201);
    }

    /**
     * Display the specified salary bifurcation.
     */
    public function show(int $id): JsonResponse
    {
        $bifurcation = SalaryBifurcation::withTrashed()
            ->with(['createdBy:id,name', 'updatedBy:id,name', 'deletedBy:id,name'])
            ->find($id);

        if (!$bifurcation) {
            return response()->json([
                'success' => false,
                'message' => 'Salary bifurcation not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Salary bifurcation retrieved successfully.',
            'data'    => $bifurcation,
        ], 200);
    }

    /**
     * Update the specified salary bifurcation.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $bifurcation = SalaryBifurcation::find($id);

        if (!$bifurcation) {
            return response()->json([
                'success' => false,
                'message' => 'Salary bifurcation not found.',
            ], 404);
        }

        $effectiveStartDate = $request->input('effective_start_date', $bifurcation->effective_start_date);

        $validator = Validator::make($request->all(), [
            'actual_salary'        => 'sometimes|required|numeric|min:0',
            'books_basic'          => 'sometimes|required|numeric|min:0',
            'bonus'                => 'nullable|numeric|min:0',
            'books_hra'            => 'nullable|numeric|min:0',
            'other_allowances'     => 'nullable|numeric|min:0',
            'total_salary'         => 'nullable|numeric|min:0',
            'effective_start_date' => 'sometimes|required|date',
            'effective_end_date'   => 'nullable|date|after_or_equal:' . $effectiveStartDate,
            'is_active'            => 'nullable|boolean',
        ]);

        $validator->after(function ($validator) use ($request, $bifurcation): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $actualSalary = $request->input('actual_salary', $bifurcation->actual_salary);
            $booksBasic = $request->input('books_basic', $bifurcation->books_basic);
            $newStartDate = $request->input('effective_start_date', $bifurcation->effective_start_date);
            $newEndDate = $request->has('effective_end_date')
                ? $request->input('effective_end_date')
                : $bifurcation->effective_end_date;

            if ($actualSalary !== null && $booksBasic !== null && (float) $actualSalary === (float) $booksBasic) {
                $validator->errors()->add(
                    'books_basic',
                    'The actual salary and books basic values cannot be identical.'
                );
            }

            $existingRecord = SalaryBifurcation::withTrashed()
                ->where('id', '!=', $bifurcation->id)
                ->where(function ($q): void {
                    $q->where('is_active', false)
                        ->orWhere('is_deleted', true)
                        ->orWhereNotNull('deleted_at');
                })
                ->where(function ($query) use ($newStartDate, $newEndDate): void {
                    $query->whereDate('effective_start_date', $newStartDate)
                        ->orWhere(function ($q) use ($newStartDate): void {
                            $q->whereDate('effective_start_date', '<=', $newStartDate)
                                ->where(function ($sub) use ($newStartDate): void {
                                    $sub->whereNull('effective_end_date')
                                        ->orWhereDate('effective_end_date', '>=', $newStartDate);
                                });
                        });

                    if (!empty($newEndDate)) {
                        $query->orWhere(function ($q) use ($newStartDate, $newEndDate): void {
                            $q->whereDate('effective_start_date', '<=', $newEndDate)
                                ->where(function ($sub) use ($newStartDate): void {
                                    $sub->whereNull('effective_end_date')
                                        ->orWhereDate('effective_end_date', '>=', $newStartDate);
                                });
                        });
                    }
                })
                ->first();

            if ($existingRecord) {
                $dateRange = !empty($existingRecord->date_range)
                    ? $existingRecord->date_range
                    : $this->formatDateRange($existingRecord->effective_start_date, $existingRecord->effective_end_date);
                $isDeleted = $existingRecord->trashed() || (bool) $existingRecord->is_deleted;
                $stageMode = $isDeleted ? 'deleted' : 'inactive';
                $validator->errors()->add(
                    'effective_start_date',
                    "A salary bifurcation entry with date range ({$dateRange}) already exists in the system in {$stageMode} mode."
                );
                return;
            }

            // 1. Previous record overlap check (record starting before or on the current start date)
            $previous = SalaryBifurcation::where('id', '!=', $bifurcation->id)
                ->whereDate('effective_start_date', '<=', $newStartDate)
                ->orderByDesc('effective_start_date')
                ->orderByDesc('id')
                ->first();

            if ($previous) {
                if (empty($previous->effective_end_date)) {
                    $validator->errors()->add(
                        'effective_start_date',
                        'The previous salary bifurcation record has an empty effective end date. Please update the previous record\'s end date before updating the current record.'
                    );
                } elseif (Carbon::parse($newStartDate)->lte(Carbon::parse($previous->effective_end_date))) {
                    $prevEndDate = Carbon::parse($previous->effective_end_date)->format('d-m-Y');
                    $validator->errors()->add(
                        'effective_start_date',
                        "The effective start date must be greater than the previous record's effective end date ({$prevEndDate})."
                    );
                }
            }

            // 2. Next record overlap check (record starting after or on the current start date)
            $next = SalaryBifurcation::where('id', '!=', $bifurcation->id)
                ->whereDate('effective_start_date', '>=', $newStartDate)
                ->orderBy('effective_start_date')
                ->orderBy('id')
                ->first();

            if ($next) {
                $nextStartDate = Carbon::parse($next->effective_start_date)->format('d-m-Y');
                if (empty($newEndDate)) {
                    $validator->errors()->add(
                        'effective_end_date',
                        "A subsequent salary bifurcation record exists ({$nextStartDate}); the effective end date cannot be left blank."
                    );
                } elseif (Carbon::parse($newEndDate)->gte(Carbon::parse($next->effective_start_date))) {
                    $validator->errors()->add(
                        'effective_end_date',
                        "The effective end date must be earlier than the next record's effective start date ({$nextStartDate})."
                    );
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $validated['updated_by'] = $request->user()?->id;
        $bifurcation->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Salary bifurcation updated successfully.',
            'data'    => $bifurcation->load(['createdBy:id,name', 'updatedBy:id,name']),
        ], 200);
    }

    /**
     * Remove the specified salary bifurcation (Soft Delete).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $bifurcation = SalaryBifurcation::find($id);

        if (!$bifurcation) {
            return response()->json([
                'success' => false,
                'message' => 'Salary bifurcation not found.',
            ], 404);
        }

        $bifurcation->is_active = false;
        $bifurcation->is_deleted = true;
        $bifurcation->deleted_by = $request->user()?->id;
        $bifurcation->save();
        $bifurcation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Salary bifurcation deleted successfully.',
        ], 200);
    }

    /**
     * Restore the soft-deleted salary bifurcation.
     */
    public function restore(int $id): JsonResponse
    {
        $bifurcation = SalaryBifurcation::onlyTrashed()->find($id);

        if (!$bifurcation) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted salary bifurcation not found.',
            ], 404);
        }

        $restoreStart = $bifurcation->effective_start_date;
        $restoreEnd   = $bifurcation->effective_end_date;

        $overlap = SalaryBifurcation::where('id', '!=', $bifurcation->id)
            ->where(function ($query) use ($restoreStart): void {
                $query->whereNull('effective_end_date')
                    ->orWhereDate('effective_end_date', '>=', $restoreStart);
            })
            ->when(!empty($restoreEnd), function ($query) use ($restoreEnd): void {
                $query->whereDate('effective_start_date', '<=', $restoreEnd);
            })
            ->first();

        if ($overlap) {
            $overlapRange = !empty($overlap->date_range)
                ? $overlap->date_range
                : $this->formatDateRange($overlap->effective_start_date, $overlap->effective_end_date);

            return response()->json([
                'success' => false,
                'message' => "Cannot restore record. The effective date range overlaps with an existing salary bifurcation record ({$overlapRange}).",
            ], 409);
        }

        $bifurcation->restore();
        $bifurcation->is_active = true;
        $bifurcation->is_deleted = false;
        $bifurcation->deleted_by = null;
        $bifurcation->save();

        return response()->json([
            'success' => true,
            'message' => 'Salary bifurcation restored successfully.',
            'data'    => $bifurcation,
        ], 200);
    }

    /**
     * Format start and end dates as a consistent range string (e.g. "01-04-2024 to 31-03-2025").
     */
    private function formatDateRange(mixed $startDate, mixed $endDate): string
    {
        if (empty($startDate)) {
            return 'N/A';
        }

        $formattedStart = Carbon::parse($startDate)->format('d-m-Y');

        if (empty($endDate)) {
            return "{$formattedStart} onwards";
        }

        $formattedEnd = Carbon::parse($endDate)->format('d-m-Y');

        return "{$formattedStart} to {$formattedEnd}";
    }
}