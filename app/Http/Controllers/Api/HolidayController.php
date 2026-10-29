<?php

namespace App\Http\Controllers\Api;

use App\Exports\HolidayExport;
use App\Http\Controllers\Controller;
use App\Imports\HolidayImport;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:holiday-view|holiday-create|holiday-edit|holiday-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:holiday-create', ['only' => ['store']]);
        $this->middleware('permission:holiday-edit', ['only' => ['update']]);
        $this->middleware('permission:holiday-delete', ['only' => ['destroy']]);
        $this->middleware('permission:holiday-restore', ['only' => ['restore']]);
        $this->middleware('permission:holiday-export', ['only' => ['export']]);
        $this->middleware('permission:holiday-import', ['only' => ['import', 'importTemplate', 'importErrors', 'importStructure']]);
    }

    /**
     * Import holidays from a CSV file.
     */
    public function import(Request $request): JsonResponse
    {
        $templateUrl = route('holidays.import.template');

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

        $holidayImport = new HolidayImport();

        try {
            $imported = $holidayImport->import($request->file('file'));

            if ($holidayImport->hasErrors()) {
                $errorFile = $holidayImport->storeErrors();

                return response()->json([
                    'success'      => false,
                    'message'      => 'Some holidays could not be imported. Download the error sheet for row-specific messages.',
                    'imported'     => $imported,
                    'failed'       => count($holidayImport->errors()),
                    'errors'       => $holidayImport->errors(),
                    'errors_url'   => route('holidays.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success'      => true,
                'message'      => 'Holidays imported successfully.',
                'imported'     => $imported,
                'failed'       => 0,
                'errors'       => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import holidays.',
                'error'   => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Export holidays as a CSV file.
     */
    public function export(Request $request)
    {
        return (new HolidayExport())->download($request);
    }

    /**
     * Download the CSV template for holiday import.
     */
    public function importTemplate()
    {
        return (new HolidayImport())->downloadTemplate();
    }

    /**
     * Download CSV containing failed rows from previous import.
     */
    public function importErrors(string $filename)
    {
        $path = 'holiday-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'holiday-import-errors.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    /**
     * Return structure and header specification expected by import.
     */
    public function importStructure(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Holiday import structure retrieved successfully.',
            'data'    => [
                'headers' => ['holiday_date', 'holiday_title', 'holiday_type', 'is_active'],
                'columns' => [
                    ['name' => 'holiday_date', 'type' => 'date', 'required' => true, 'description' => 'Holiday date (YYYY-MM-DD).'],
                    ['name' => 'holiday_title', 'type' => 'text', 'required' => true, 'description' => 'Title or occasion of the holiday.'],
                    ['name' => 'holiday_type', 'type' => 'text', 'required' => false, 'description' => 'Accepted values: full day, half day. Default: full day.'],
                    ['name' => 'is_active', 'type' => 'boolean', 'required' => false, 'description' => 'Accepted values: true, false, 1, 0, active, inactive. Default: true.'],
                ],
                'template_url' => route('holidays.import.template'),
            ],
        ]);
    }

    /**
     * Display a listing of holidays.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $page    = (int) $request->query('page', 1);
            $limit   = (int) $request->query('limit', 10);
            $search  = mb_strtolower(trim((string) $request->query('search', '')));
            $status  = strtolower(trim((string) ($request->query('status') ?? $request->query('is_active', ''))));
            $trashed = strtolower(trim((string) $request->query('trashed', '')));
            $year    = $request->query('year');
            $month   = $request->query('month');

            $currentUser = $request->user();
            $canViewTrashed = $currentUser
                && method_exists($currentUser, 'hasAnyRole')
                && $currentUser->hasAnyRole(['Super Admin', 'Admin']);

            $showTrashed = $canViewTrashed
                && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');

            $query = Holiday::query()
                ->with(['createdBy:id,name', 'updatedBy:id,name'])
                ->where('is_delete', $showTrashed)
                ->when($search !== '', function ($q) use ($search): void {
                    $q->where(function ($sub): void {
                        $sub->whereRaw('LOWER(holiday_title) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(holiday_type) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($q) use ($status): void {
                    $q->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->when($request->filled('holiday_type'), function ($q) use ($request): void {
                    $q->where('holiday_type', $request->query('holiday_type'));
                })
                ->when($request->filled('start_date') && $request->filled('end_date'), function ($q) use ($request): void {
                    $q->betweenDates($request->query('start_date'), $request->query('end_date'));
                })
                ->when($year && $month, function ($q) use ($year, $month): void {
                    $q->forMonth((int) $year, (int) $month);
                })
                ->when($year && ! $month, function ($q) use ($year): void {
                    $q->forYear((int) $year);
                })
                ->orderBy('holiday_date', 'asc');

            $holidays = $query->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success'    => true,
                'message'    => 'Holidays retrieved successfully.',
                'data'       => $holidays->items(),
                'pagination' => [
                    'current_page' => $holidays->currentPage(),
                    'per_page'     => $holidays->perPage(),
                    'total'        => $holidays->total(),
                    'last_page'    => $holidays->lastPage(),
                    'from'         => $holidays->firstItem(),
                    'to'           => $holidays->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching holidays.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created holiday.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->has('holiday_title') && is_string($request->holiday_title)) {
            $request->merge([
                'holiday_title' => mb_strtolower(trim($request->holiday_title)),
            ]);
        }

        if ($request->has('holiday_type') && is_string($request->holiday_type)) {
            $request->merge([
                'holiday_type' => mb_strtolower(trim($request->holiday_type)),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'holiday_date'  => [
                'required',
                'date_format:Y-m-d',
                Rule::unique('holidays', 'holiday_date')
                    ->where(fn ($q) => $q->where('is_delete', false)),
            ],
            'holiday_title' => ['required', 'string', 'max:255'],
            'holiday_type'  => ['required', Rule::in(['full day', 'half day'])],
            'is_active'     => ['nullable', 'boolean'],
        ], [
            'holiday_date.unique' => 'A holiday has already been registered on this date.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $validated = $validator->validated();
            $validated['is_active']  = $validated['is_active'] ?? true;
            $validated['created_by'] = $request->user()?->id;

            $holiday = Holiday::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Holiday created successfully.',
                'data'    => $holiday->load(['createdBy:id,name']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create holiday.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified holiday.
     */
    public function show(int|string $id): JsonResponse
    {
        $holiday = Holiday::with(['createdBy:id,name', 'updatedBy:id,name'])
            ->where('is_delete', false)
            ->find($id);

        if (! $holiday) {
            return response()->json([
                'success' => false,
                'message' => 'Holiday not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $holiday,
        ], 200);
    }

    /**
     * Update the specified holiday.
     */
    public function update(Request $request, int|string $id): JsonResponse
    {
        $holiday = Holiday::where('is_delete', false)->find($id);

        if (! $holiday) {
            return response()->json([
                'success' => false,
                'message' => 'Holiday not found.',
            ], 404);
        }

        if ($request->has('holiday_title') && is_string($request->holiday_title)) {
            $request->merge([
                'holiday_title' => mb_strtolower(trim($request->holiday_title)),
            ]);
        }

        if ($request->has('holiday_type') && is_string($request->holiday_type)) {
            $request->merge([
                'holiday_type' => mb_strtolower(trim($request->holiday_type)),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'holiday_date'  => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                Rule::unique('holidays', 'holiday_date')
                    ->ignore($holiday->id)
                    ->where(fn ($q) => $q->where('is_delete', false)),
            ],
            'holiday_title' => ['sometimes', 'required', 'string', 'max:255'],
            'holiday_type'  => ['sometimes', 'required', Rule::in(['full day', 'half day'])],
            'is_active'     => ['sometimes', 'boolean'],
        ], [
            'holiday_date.unique' => 'A holiday has already been registered on this date.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $validated = $validator->validated();
            $validated['updated_by'] = $request->user()?->id;

            $holiday->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Holiday updated successfully.',
                'data'    => $holiday->fresh(['updatedBy:id,name']),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update holiday.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Soft-delete the specified holiday.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $holiday = Holiday::where('is_delete', false)->find($id);

        if (! $holiday) {
            return response()->json([
                'success' => false,
                'message' => 'Holiday not found.',
            ], 404);
        }

        $holiday->update([
            'is_active'  => false,
            'is_delete'  => true,
            'deleted_by' => $request->user()?->id,
            'deleted_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Holiday deleted successfully.',
        ], 200);
    }

    /**
     * Restore a soft-deleted holiday.
     */
    public function restore(Request $request, int|string $id): JsonResponse
    {
        $currentUser = $request->user();
        $canRestore = $currentUser
            && method_exists($currentUser, 'hasAnyRole')
            && $currentUser->hasAnyRole(['Super Admin', 'Admin']);

        if (! $canRestore) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only Super Admin or Admin can restore holidays.',
            ], 403);
        }

        $holiday = Holiday::where('is_delete', true)->find($id);

        if (! $holiday) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted holiday not found.',
            ], 404);
        }

        $duplicate = Holiday::where('is_delete', false)
            ->whereDate('holiday_date', $holiday->holiday_date)
            ->where('id', '!=', $holiday->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. A holiday on this date already exists.',
            ], 409);
        }

        $holiday->update([
            'is_delete'  => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active'  => true,
            'updated_by' => $currentUser?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Holiday restored successfully.',
            'data'    => $holiday->load(['createdBy:id,name', 'updatedBy:id,name']),
        ], 200);
    }
}