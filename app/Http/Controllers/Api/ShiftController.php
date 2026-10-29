<?php

namespace App\Http\Controllers\Api;

use App\Exports\ShiftExport;
use App\Http\Controllers\Controller;
use App\Imports\ShiftImport;
use App\Models\Shift;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShiftController extends Controller
{
    /**
     * Import shifts from a CSV file.
     */
    public function import(Request $request)
    {
        $templateUrl = route('shifts.import.template');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $shiftImport = new ShiftImport();

        try {
            $imported = $shiftImport->import($request->file('file'));

            if ($shiftImport->hasErrors()) {
                $errorFile = $shiftImport->storeErrors();

                return response()->json([
                    'success' => false,
                    'message' => 'Some shifts could not be imported.',
                    'imported' => $imported,
                    'failed' => count($shiftImport->errors()),
                    'errors' => $shiftImport->errors(),
                    'errors_url' => route('shifts.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Shifts imported successfully.',
                'imported' => $imported,
                'failed' => 0,
                'errors' => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import shifts.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Export all shifts as a CSV file.
     */
    public function export(Request $request)
    {
        return (new ShiftExport())->download($request);
    }

    /**
     * Download the CSV template used by the shift import endpoint.
     */
    public function importTemplate()
    {
        return (new ShiftImport())->downloadTemplate();
    }

    /**
     * Download the CSV containing rejected shift import rows.
     */
    public function importErrors(string $filename)
    {
        $path = 'shift-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'shift-import-errors.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    /**
     * Return the fields required by the shift import file.
     */
    public function importStructure()
    {
        return response()->json([
            'success' => true,
            'message' => 'Shift import structure retrieved successfully.',
            'data' => [
                'headers' => [
                    'shift_code', 'shift_name', 'shift_start_time', 'shift_end_time', 'grace_time',
                    'full_day_min_hours', 'short_leave_min_hours', 'half_day_min_hours', 'no_salary_max_hours', 'effective_from',
                    'effective_to', 'location_names', 'designation_names', 'is_active'
                ],
                'columns' => [
                    ['name' => 'shift_code', 'type' => 'text', 'required' => true],
                    ['name' => 'shift_name', 'type' => 'text', 'required' => true],
                    ['name' => 'shift_start_time', 'type' => 'time', 'required' => true, 'description' => 'Use H:i:s format (e.g., 09:30:00).'],
                    ['name' => 'shift_end_time', 'type' => 'time', 'required' => true, 'description' => 'Use H:i:s format (e.g., 18:30:00).'],
                    ['name' => 'grace_time', 'type' => 'integer', 'required' => true, 'description' => 'Grace period in minutes.'],
                    ['name' => 'full_day_min_hours', 'type' => 'interval', 'required' => true, 'description' => 'Use HH:MM:SS format (e.g., 08:00:00).'],
                    ['name' => 'short_leave_min_hours', 'type' => 'interval', 'required' => true, 'description' => 'Use HH:MM:SS format.'],
                    ['name' => 'half_day_min_hours', 'type' => 'interval', 'required' => true, 'description' => 'Use HH:MM:SS format.'],
                    ['name' => 'no_salary_max_hours', 'type' => 'interval', 'required' => true, 'description' => 'Use HH:MM:SS format.'],
                    ['name' => 'effective_from', 'type' => 'date', 'required' => true, 'description' => 'Use YYYY-MM-DD format.'],
                    ['name' => 'effective_to', 'type' => 'date', 'required' => true, 'description' => 'Use YYYY-MM-DD format.'],
                    ['name' => 'location_names', 'type' => 'text', 'required' => true, 'description' => 'Comma-separated existing location names.'],
                    ['name' => 'designation_names', 'type' => 'text', 'required' => true, 'description' => 'Comma-separated existing designation names.'],
                    [
                        'name' => 'is_active',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Optional. Defaults to true. Accepted values: true, false, yes, no, active, inactive, 1, 0.',
                    ],
                ],
                'template_url' => route('shifts.import.template'),
            ],
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
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

            $shifts = Shift::withTrashed()->with(['shiftLocationDesignations.location', 'shiftLocationDesignations.designation'])
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->whereRaw('LOWER(shift_code) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(shift_name) LIKE ?', ["%{$search}%"]);
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
                'message' => 'Shifts retrieved successfully.',
                'data' => $shifts->items(),
                'pagination' => [
                    'current_page' => $shifts->currentPage(),
                    'per_page'     => $shifts->perPage(),
                    'total'        => $shifts->total(),
                    'last_page'    => $shifts->lastPage(),
                    'from'         => $shifts->firstItem(),
                    'to'           => $shifts->lastItem(),
                ],
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving shifts.',
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
            'shift_code' => ['required', 'string', 'max:30', Rule::unique('shifts','shift_code')],
            'shift_name' => 'required|string|max:100',
            'shift_start_time' => 'required|date_format:H:i:s',
            'shift_end_time' => 'required|date_format:H:i:s|after:shift_start_time',
            'grace_time' => 'required|integer|min:0',
            'full_day_min_hours' => 'required|numeric', // Assuming interval is stored as string like '08:00:00'
            'short_leave_min_hours' => 'required|numeric',
            'half_day_min_hours' => 'required|numeric',
            'no_salary_max_hours' => 'required|numeric',
            'effective_from' => 'required|date',
            'effective_to' => 'required|date|after_or_equal:effective_from',
            'is_active' => 'boolean',
            'locations' => 'required|array|min:1',
            'locations.*' => 'required|integer|exists:locations,id',
            'designations' => 'required|array|min:1',
            'designations.*' => 'required|integer|exists:designations,id',
        ]);

        $this->validateShiftName($validator, $request->input('shift_name'));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            DB::beginTransaction();

            $shiftData = $validator->validated();
            $shiftData['created_by'] = Auth::id();

            // Convert shift_name to lowercase
            if (isset($shiftData['shift_name'])) {
                $shiftData['shift_name'] = mb_strtolower($shiftData['shift_name']);
            }

            $shift = Shift::create($shiftData);

            $locations = $request->input('locations', []);
            $designations = $request->input('designations', []);
            $associations = [];
            foreach ($locations as $location_id) {
                foreach ($designations as $designation_id) {
                    $associations[] = ['location_id' => $location_id, 'designation_id' => $designation_id];
                }
            }
            $shift->shiftLocationDesignations()->createMany($associations);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Shift created successfully.',
                'data' => $shift->load('shiftLocationDesignations.location', 'shiftLocationDesignations.designation')
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the shift.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Shift $shift)
    {
        if ($shift->is_delete) {
            return response()->json(['success' => false, 'message' => 'Shift not found.'], 404);
        }
        $shift->load('shiftLocationDesignations');

        // Collection Filtering
        $locationIds = $shift->shiftLocationDesignations           
            ->pluck('location_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $designationIds = $shift->shiftLocationDesignations            
            ->pluck('designation_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $shiftData = $shift->toArray();
        unset($shiftData['shift_location_designations']);
      //  $shiftData['locations'] = $locationIds;
     //   $shiftData['designations'] = $designationIds;
        $shiftData['location_ids'] = $locationIds;
        $shiftData['designation_ids'] = $designationIds;

        return response()->json([
            'success' => true,
            'message' => 'Shift details retrieved successfully.',
            'data' => $shiftData
        ], 200);
    }
   

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Shift $shift)
    {
        if ($shift->is_delete) {
            return response()->json(['success' => false, 'message' => 'Shift not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'shift_code' => ['required', 'string', 'max:30', Rule::unique('shifts','shift_code')->ignore($shift->id)],
            'shift_name' => 'required|string|max:100',
            'shift_start_time' => 'required|date_format:H:i:s',
            'shift_end_time' => 'required|date_format:H:i:s|after:shift_start_time',
            'grace_time' => 'required|integer|min:0',
            'full_day_min_hours' => 'required|numeric',
            'short_leave_min_hours' => 'required|numeric',
            'half_day_min_hours' => 'required|numeric',
            'no_salary_max_hours' => 'required|numeric',
            'effective_from' => 'required|date',
            'effective_to' => 'required|date|after_or_equal:effective_from',
            'is_active' => 'boolean',
            'locations' => 'required|array|min:1',
            'locations.*' => 'required|integer|exists:locations,id',
            'designations' => 'required|array|min:1',
            'designations.*' => 'required|integer|exists:designations,id',
        ]);

        $this->validateShiftName($validator, $request->input('shift_name'), $shift->id);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            DB::beginTransaction();

            $shiftData = $validator->validated();
            $shiftData['updated_by'] = Auth::id();

            // Convert shift_name to lowercase
            if (isset($shiftData['shift_name'])) {
                $shiftData['shift_name'] = mb_strtolower($shiftData['shift_name']);
            }

            $shift->update($shiftData);

            // Sync the location and designation associations
            $shift->shiftLocationDesignations()->delete();
            $locations = $request->input('locations', []);
            $designations = $request->input('designations', []);
            $associations = [];
            foreach ($locations as $location_id) {
                foreach ($designations as $designation_id) {
                    $associations[] = ['location_id' => $location_id, 'designation_id' => $designation_id];
                }
            }
            $shift->shiftLocationDesignations()->createMany($associations);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Shift updated successfully.',
                'data' => $shift->load('shiftLocationDesignations.location', 'shiftLocationDesignations.designation')
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the shift.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Shift $shift)
    {
        if ($shift->is_delete) {
            return response()->json(['success' => false, 'message' => 'Shift not found.'], 404);
        }

        try {
            DB::beginTransaction();
            $shift->is_delete = true;
            $shift->deleted_by = Auth::id();
            $shift->deleted_at = now();
            $shift->save();
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Shift deleted successfully.'], 200);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the shift.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted shift.
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
                'message' => 'Unauthorized. Only Super Admin or Admin can restore shifts.',
            ], 403);
        }

        $shift = Shift::withTrashed()->where('is_delete', true)->find($id);

        if (!$shift) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted shift not found.',
            ], 404);
        }

        $duplicateName = Shift::withTrashed()
            ->where('is_delete', false)
            ->whereRaw('LOWER(shift_name) = ?', [mb_strtolower(trim($shift->shift_name))])
            ->where('id', '!=', $shift->id)
            ->exists();
        $duplicateCode = Shift::withTrashed()
            ->where('is_delete', false)
            ->whereRaw('LOWER(shift_code) = ?', [mb_strtolower(trim($shift->shift_code))])
            ->where('id', '!=', $shift->id)
            ->exists();

        if ($duplicateName || $duplicateCode) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. An active shift with this name or code already exists.',
            ], 409);
        }

        $shift->restore();
        $shift->update([
            'is_delete' => false,
            'deleted_by' => null,
            'is_active' => true,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shift restored successfully.',
            'data' => $shift,
        ], 200);
    }

    private function validateShiftName(ValidatorContract $validator, ?string $name, ?int $ignoreId = null): void
    {
        if ($name === null || trim($name) === '') {
            return;
        }

        $validator->after(function ($validator) use ($name, $ignoreId): void {
            $existing = Shift::withTrashed()
                ->whereRaw('LOWER(shift_name) = ?', [mb_strtolower(trim($name))])
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->orderBy('is_delete', 'asc')
                ->orderByDesc('id')
                ->first();

            if (!$existing) {
                return;
            }

            if ($existing->is_delete) {
                $message = 'The shift name already exists in deleted stage.';
            } elseif (!$existing->is_active) {
                $message = 'The shift name already exists in deactivated stage.';
            } else {
                $message = 'The shift name has already been taken.';
            }

            $validator->errors()->add('shift_name', $message);
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
            'message' => $duplicate ? 'Shift name already exists.' : 'Validation failed.',
            'errors' => $errors,
        ], $duplicate ? 409 : 422);
    }
}