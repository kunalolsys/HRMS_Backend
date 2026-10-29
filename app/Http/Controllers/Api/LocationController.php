<?php

namespace App\Http\Controllers\Api;

use App\Exports\LocationExport;
use App\Http\Controllers\Controller;
use App\Imports\LocationImport;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Location;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorContract;

class LocationController extends Controller
{
    public function import(Request $request)
    {
        $templateUrl = route('locations.import.template');
        $validator = Validator::make($request->all(), ['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $locationImport = new LocationImport();
        try {
            $imported = $locationImport->import($request->file('file'));
            if ($locationImport->hasErrors()) {
                $errorFile = $locationImport->storeErrors();
                return response()->json([
                    'success' => false,
                    'message' => 'Some locations could not be imported. Download the error sheet for row-specific messages.',
                    'imported' => $imported,
                    'failed' => count($locationImport->errors()),
                    'errors' => $locationImport->errors(),
                    'errors_url' => route('locations.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }
            return response()->json([
                'success' => true,
                'message' => 'Locations imported successfully.',
                'imported' => $imported,
                'failed' => 0,
                'errors' => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json(['success' => false, 'message' => 'Failed to import locations.', 'error' => $exception->getMessage()], 500);
        }
    }

    public function export(Request $request)
    {
        return (new LocationExport())->download($request);
    }

    public function importTemplate()
    {
        return (new LocationImport())->downloadTemplate();
    }

    public function importErrors(string $filename)
    {
        $path = 'location-import-errors/' . basename($filename);
        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');
        return response()->download(Storage::disk('local')->path($path), 'location-import-errors.csv', ['Content-Type' => 'text/csv']);
    }

    public function importStructure()
    {
        return response()->json([
            'success' => true,
            'message' => 'Location import structure retrieved successfully.',
            'data' => [
                'headers' => ['location_code', 'location_name', 'group_type', 'state', 'cost_center_id', 'est_registration', 'renewal_date', 'biometric_device_id', 'weekly_off_day', 'is_active'],
                'columns' => [
                    ['name' => 'location_code', 'type' => 'text', 'required' => true],
                    ['name' => 'location_name', 'type' => 'text', 'required' => true],
                    ['name' => 'group_type', 'type' => 'text', 'required' => false],
                    ['name' => 'state', 'type' => 'text', 'required' => false],
                    ['name' => 'cost_center_id', 'type' => 'integer', 'required' => false, 'description' => 'Existing cost_centers.id value.'],
                    ['name' => 'est_registration', 'type' => 'text', 'required' => false],
                    ['name' => 'renewal_date', 'type' => 'date', 'required' => false, 'description' => 'Use YYYY-MM-DD.'],
                    ['name' => 'biometric_device_id', 'type' => 'text', 'required' => false],
                    ['name' => 'weekly_off_day', 'type' => 'text', 'required' => false],
                    ['name' => 'is_active', 'type' => 'boolean', 'required' => false, 'description' => 'Optional. Defaults to true. Accepted values: true, false, yes, no, active, inactive, 1, 0.'],
                ],
                'template_url' => route('locations.import.template'),
            ],
        ]);
    }

    public function index(Request $request)
    {
        try {
            $page = $request->query('page', 1);
            $limit = $request->query('limit', 10);
            $search = mb_strtolower(trim((string) $request->query('search', '')));
            $status = strtolower(trim((string) $request->query('status', '')));
            $trashed = strtolower(trim((string) $request->query('trashed', '')));
            $currentUser = $request->user();
            $canViewTrashed = $currentUser
                && method_exists($currentUser, 'hasAnyRole')
                && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
            $showTrashed = $canViewTrashed
                && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');
            $locations = Location::with(['costCenter:id,code,name', 'creator:id,name', 'updater:id,name'])
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        foreach (['location_code', 'location_name', 'group_type', 'state', 'weekly_off_day'] as $column) {
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
                'message' => 'Locations retrieved successfully.',
                'data' => $locations->items(),
                'pagination' => [
                    'current_page' => $locations->currentPage(), 'per_page' => $locations->perPage(), 'total' => $locations->total(),
                    'last_page' => $locations->lastPage(), 'from' => $locations->firstItem(), 'to' => $locations->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred while retrieving locations.', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules());
        $this->validateLocationDuplicates($validator, $request->only(['location_code', 'location_name']));

        if ($validator->fails()) return $this->validationErrorResponse($validator);
        $validated = $validator->validated();

        if (isset($validated['cost_center_id'])) {
            $costCenter = CostCenter::find($validated['cost_center_id']);
            if ($costCenter && !$costCenter->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected cost center is inactive and cannot be assigned.',
                ], 422);
            }
        }
        try {
            $validated['created_by'] = Auth::id();
            
            $location = Location::create($validated);
            return response()->json(['success' => true, 'message' => 'Location created successfully.', 'data' => $location->load(['costCenter:id,code,name', 'creator:id,name', 'updater:id,name'])], 201);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'create');
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to create location.', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Location $location)
    {
        if ($location->is_delete) return response()->json(['success' => false, 'message' => 'Location not found.'], 404);
        try {
            return response()->json(['success' => true, 'message' => 'Location retrieved successfully.', 'data' => $location->load(['costCenter:id,code,name', 'creator:id,name', 'updater:id,name'])]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred while retrieving the location.', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, Location $location)
    {
        if ($location->is_delete) return response()->json(['success' => false, 'message' => 'Location not found.'], 404);
        $validator = Validator::make($request->all(), $this->rules($location->id));
        $this->validateLocationDuplicates(
            $validator,
            $request->only(['location_code', 'location_name']),
            $location->id
        );

        if ($validator->fails()) return $this->validationErrorResponse($validator);
        $validated = $validator->validated();

        if (isset($validated['cost_center_id'])) {
            $costCenter = CostCenter::find($validated['cost_center_id']);
            if ($costCenter && !$costCenter->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected cost center is inactive and cannot be assigned.',
                ], 422);
            }
        }
        try {
            $validated['updated_by'] = Auth::id();
            
            $location->update($validated);
            return response()->json(['success' => true, 'message' => 'Location updated successfully.', 'data' => $location->load(['costCenter:id,code,name', 'creator:id,name', 'updater:id,name'])]);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'update');
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update location.', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Location $location)
    {
        if ($location->is_delete) return response()->json(['success' => false, 'message' => 'Location not found.'], 404);
        try {
            if (Employee::where('location_id', $location->id)->where('is_delete', false)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This location is linked to one or more employees. Please reassign or unlink them first, then delete the location.',
                ], 409);
            }

            $location->update(['is_delete' => true, 'deleted_by' => Auth::id(), 'deleted_at' => now()]);
            return response()->json(['success' => true, 'message' => 'Location deleted successfully.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete location.', 'error' => $e->getMessage()], 500);
        }
    }

    public function restore(int $id)
    {
        $currentUser = Auth::user();
        $canRestore = $currentUser
            && method_exists($currentUser, 'hasAnyRole')
            && $currentUser->hasAnyRole(['Super Admin', 'Admin']);

        if (!$canRestore) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only Super Admin or Admin can restore locations.',
            ], 403);
        }

        $location = Location::where('is_delete', true)->find($id);

        if (!$location) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted location not found.',
            ], 404);
        }

        $duplicate = Location::where('is_delete', false)
            ->where(function ($query) use ($location): void {
                $query->whereRaw('LOWER(location_code) = ?', [mb_strtolower(trim($location->location_code))])
                    ->orWhereRaw('LOWER(location_name) = ?', [mb_strtolower(trim($location->location_name))]);
            })
            ->where('id', '!=', $location->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. A location with this code or name already exists.',
            ], 409);
        }

        $location->update([
            'is_delete' => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active' => true,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Location restored successfully.',
            'data' => $location->load(['costCenter:id,code,name', 'creator:id,name', 'updater:id,name']),
        ], 200);
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'location_code' => ['required', 'string', 'max:255', Rule::unique('locations', 'location_code')->ignore($ignoreId, 'id')->where(fn ($query) => $query->where('is_delete', false))],
            'location_name' => ['required', 'string', 'max:255', Rule::unique('locations', 'location_name')->ignore($ignoreId, 'id')->where(fn ($query) => $query->where('is_delete', false))],
            'group_type' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'est_registration' => ['nullable', 'string'],
            'renewal_date' => ['nullable', 'date'],
            'biometric_device_id' => ['nullable', 'string'],
            'weekly_off_day' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function validateLocationDuplicates(
        ValidatorContract $validator,
        array $values,
        ?int $ignoreId = null
    ): void {
        $labels = [
            'location_code' => 'location code',
            'location_name' => 'location name',
        ];

        $validator->after(function ($validator) use ($values, $ignoreId, $labels): void {
            foreach ($labels as $column => $label) {
                $value = $values[$column] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }

                $existing = Location::whereRaw("LOWER({$column}) = ?", [mb_strtolower(trim($value))])
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
            'message' => $duplicate ? 'Location code or name already exists.' : 'Validation failed.',
            'errors' => $errors,
        ], $duplicate ? 409 : 422);
    }

    private function databaseErrorResponse(QueryException $exception, string $action)
    {
        $message = strtolower($exception->getMessage());
        if (in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation')) {
            return response()->json(['success' => false, 'message' => 'Location code or name already exists.', 'error' => 'Duplicate location record.'], 409);
        }
        return response()->json(['success' => false, 'message' => "Failed to {$action} location.", 'error' => $exception->getMessage()], 500);
    }
}
