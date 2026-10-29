<?php

namespace App\Http\Controllers\Api;

use App\Exports\StoreWeeklyOffExport;
use App\Http\Controllers\Controller;
use App\Imports\StoreWeeklyOffImport;
use App\Models\Employee;
use App\Models\StoreWeeklyOff;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StoreWeeklyOffController extends Controller
{
    /**
     * Import store weekly offs from a CSV file.
     */
    public function import(Request $request)
    {
        $templateUrl = route('store-weekly-offs.import.template');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $storeWeeklyOffImport = new StoreWeeklyOffImport();

        try {
            $imported = $storeWeeklyOffImport->import($request->file('file'));

            if ($storeWeeklyOffImport->hasErrors()) {
                $errorFile = $storeWeeklyOffImport->storeErrors();

                return response()->json([
                    'success'      => false,
                    'message'      => 'Some store weekly offs could not be imported.',
                    'imported'     => $imported,
                    'failed'       => count($storeWeeklyOffImport->errors()),
                    'errors'       => $storeWeeklyOffImport->errors(),
                    'errors_url'   => route('store-weekly-offs.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success'      => true,
                'message'      => 'Store weekly offs imported successfully.',
                'imported'     => $imported,
                'failed'       => 0,
                'errors'       => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import store weekly offs.',
                'error'   => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Export store weekly offs as a CSV file.
     */
    public function export(Request $request)
    {
        return (new StoreWeeklyOffExport())->download($request);
    }

    /**
     * Download the CSV template for store weekly offs import.
     */
    public function importTemplate()
    {
        return (new StoreWeeklyOffImport())->downloadTemplate();
    }

    /**
     * Download the CSV containing rejected import rows.
     */
    public function importErrors(string $filename)
    {
        $path = 'store-weekly-off-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'store-weekly-offs-errors.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    /**
     * Return the schema/structure expected by the import CSV.
     */
    public function importStructure()
    {
        return response()->json([
            'success' => true,
            'message' => 'Store weekly off import structure retrieved successfully.',
            'data'    => [
                'headers' => ['location_name', 'weekly_off_day', 'is_active'],
                'columns' => [
                    ['name' => 'location_name', 'type' => 'text', 'required' => true, 'description' => 'Existing location name or location code.'],
                    ['name' => 'weekly_off_day', 'type' => 'text', 'required' => true, 'description' => 'Weekly off day (e.g. Sunday, Monday, etc.).'],
                    [
                        'name'        => 'is_active',
                        'type'        => 'boolean',
                        'required'    => false,
                        'description' => 'Optional. Defaults to true. Accepted values: true, false, yes, no, active, inactive, 1, 0.',
                    ],
                ],
                'template_url' => route('store-weekly-offs.import.template'),
            ],
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $page       = $request->query('page', 1);
            $limit      = $request->query('limit', 10);
            $status     = strtolower(trim((string) $request->query('status', '')));
            $search     = mb_strtolower(trim((string) $request->query('search', '')));
            $locationId = $request->query('location_id');
            $trashed = strtolower(trim((string) $request->query('trashed', '')));
            $currentUser = $request->user();
            $canViewTrashed = $currentUser
                && method_exists($currentUser, 'hasAnyRole')
                && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
            $showTrashed = $canViewTrashed
                && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');

            $weeklyOffs = StoreWeeklyOff::withTrashed()->with(['location:id,location_name', 'creator:id,name', 'updater:id,name'])
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->whereRaw('LOWER(weekly_off_day) LIKE ?', ["%{$search}%"])
                          ->orWhereHas('location', function ($locationQuery) use ($search) {
                              $locationQuery->whereRaw('LOWER(location_name) LIKE ?', ["%{$search}%"]);
                          });
                    });
                })
                ->when(!empty($locationId), function ($query) use ($locationId) {
                    $query->where('location_id', $locationId);
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->orderByDesc('id')
                ->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Store weekly offs retrieved successfully.',
                'data' => $weeklyOffs->items(),
                'pagination' => [
                    'current_page' => $weeklyOffs->currentPage(),
                    'per_page'     => $weeklyOffs->perPage(),
                    'total'        => $weeklyOffs->total(),
                    'last_page'    => $weeklyOffs->lastPage(),
                    'from'         => $weeklyOffs->firstItem(),
                    'to'           => $weeklyOffs->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving store weekly offs.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if ($request->has('weekly_off_day') && is_string($request->weekly_off_day)) {
            $request->merge([
                'weekly_off_day' => ucfirst(strtolower(trim($request->weekly_off_day))),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'location_id' => [
                'nullable',
                'integer',
                'exists:locations,id',
            ],
            'weekly_off_day' => ['required', 'string', 'max:255', Rule::in(StoreWeeklyOff::VALID_DAYS)],
            'is_active'      => ['sometimes', 'boolean'],
        ]);
        $this->validateWeeklyOffDuplicate(
            $validator,
            $request->input('location_id'),
            $request->input('weekly_off_day')
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validatedData = $validator->validated();

        try {
            $validatedData['created_by'] = Auth::id();
            $weeklyOff = StoreWeeklyOff::create($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Store weekly off created successfully.',
                'data' => $weeklyOff
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create store weekly off.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(StoreWeeklyOff $storeWeeklyOff)
    {
        if ($storeWeeklyOff->is_delete) {
            return response()->json(['success' => false, 'message' => 'Store weekly off not found.'], 404);
        }

        try {
            $storeWeeklyOff->load(['location:id,location_name', 'creator:id,name', 'updater:id,name']);
            return response()->json([
                'success' => true,
                'message' => 'Store weekly off retrieved successfully.',
                'data' => $storeWeeklyOff
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving the record.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, StoreWeeklyOff $storeWeeklyOff)
    {
        if ($storeWeeklyOff->is_delete) {
            return response()->json(['success' => false, 'message' => 'Store weekly off not found.'], 404);
        }

        if ($request->has('weekly_off_day') && is_string($request->weekly_off_day)) {
            $request->merge([
                'weekly_off_day' => ucfirst(strtolower(trim($request->weekly_off_day))),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'location_id' => [
                'nullable',
                'integer',
                'exists:locations,id',
            ],
            'weekly_off_day' => ['required', 'string', 'max:255', Rule::in(StoreWeeklyOff::VALID_DAYS)],
            'is_active'      => ['sometimes', 'boolean'],
        ]);
        $this->validateWeeklyOffDuplicate(
            $validator,
            $request->input('location_id', $storeWeeklyOff->location_id),
            $request->input('weekly_off_day'),
            $storeWeeklyOff->id
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validatedData = $validator->validated();

        try {
            $validatedData['updated_by'] = Auth::id();
            $storeWeeklyOff->update($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Store weekly off updated successfully.',
                'data' => $storeWeeklyOff->load(['location:id,location_name', 'creator:id,name', 'updater:id,name'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update store weekly off.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(StoreWeeklyOff $storeWeeklyOff)
    {
        if ($storeWeeklyOff->is_delete) {
            return response()->json(['success' => false, 'message' => 'Store weekly off not found.'], 404);
        }

        try {
            $employeeQuery = Employee::where('is_delete', false)
                ->whereRaw('LOWER(weekly_off) = ?', [mb_strtolower(trim($storeWeeklyOff->weekly_off_day))]);

            if ($storeWeeklyOff->location_id) {
                $employeeQuery->where('location_id', $storeWeeklyOff->location_id);
            }

            if ($employeeQuery->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This store weekly off is linked to one or more active employees. Please reassign their weekly off first, then delete it.',
                ], 409);
            }

            $storeWeeklyOff->update([
                'is_delete'  => true,
                'deleted_by' => Auth::id(),
                'deleted_at' => now(),
            ]);
            $storeWeeklyOff->delete();
            return response()->json([
                'success' => true,
                'message' => 'Store weekly off deleted successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete store weekly off.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted store weekly off.
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
                'message' => 'Unauthorized. Only Super Admin or Admin can restore store weekly offs.',
            ], 403);
        }

        $weeklyOff = StoreWeeklyOff::withTrashed()
            ->where('is_delete', true)
            ->find($id);

        if (!$weeklyOff) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted store weekly off not found.',
            ], 404);
        }

        $duplicate = StoreWeeklyOff::withTrashed()
            ->where('location_id', $weeklyOff->location_id)
            ->whereRaw('LOWER(weekly_off_day) = ?', [mb_strtolower($weeklyOff->weekly_off_day)])
            ->where('is_delete', false)
            ->where('id', '!=', $weeklyOff->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. This weekly off already exists for the location.',
            ], 409);
        }

        DB::transaction(function () use ($weeklyOff): void {
            $weeklyOff->restore();
            $weeklyOff->update([
                'is_delete' => false,
                'deleted_by' => null,
                'is_active' => true,
                'updated_by' => Auth::id(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Store weekly off restored successfully.',
            'data' => $weeklyOff->load(['location:id,location_name', 'creator:id,name', 'updater:id,name']),
        ], 200);
    }

    private function validateWeeklyOffDuplicate(
        ValidatorContract $validator,
        mixed $locationId,
        mixed $weeklyOffDay,
        ?int $ignoreId = null
    ): void {
        if (!$locationId || !is_string($weeklyOffDay) || trim($weeklyOffDay) === '') {
            return;
        }

        $validator->after(function ($validator) use ($locationId, $weeklyOffDay, $ignoreId): void {
            $existing = StoreWeeklyOff::withTrashed()
                ->where('location_id', $locationId)
                ->whereRaw('LOWER(weekly_off_day) = ?', [mb_strtolower(trim($weeklyOffDay))])
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->orderBy('is_delete', 'asc')
                ->orderByDesc('id')
                ->first();

            if (!$existing) {
                return;
            }

            if ($existing->is_delete) {
                $message = 'This weekly off already exists in deleted stage for the location.';
            } elseif (!$existing->is_active) {
                $message = 'This weekly off already exists in deactivated stage for the location.';
            } else {
                $message = 'This weekly off already exists for the location.';
            }

            $validator->errors()->add('weekly_off_day', $message);
        });
    }

    private function validationErrorResponse(ValidatorContract $validator)
    {
        $errors = $validator->errors()->toArray();
        $isDuplicate = collect($errors)->flatten()->contains(function ($message): bool {
            $message = strtolower($message);

            return str_contains($message, 'already exists')
                || str_contains($message, 'already been taken');
        });

        return response()->json([
            'success' => false,
            'message' => $isDuplicate ? 'Store weekly off already exists.' : 'Validation failed.',
            'errors' => $errors,
        ], $isDuplicate ? 409 : 422);
    }
}