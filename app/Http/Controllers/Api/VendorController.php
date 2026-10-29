<?php

namespace App\Http\Controllers\Api;

use App\Exports\VendorExport;
use App\Http\Controllers\Controller;
use App\Imports\VendorImport;
use App\Models\Employee;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;

class VendorController extends Controller
{
    public function import(Request $request)
    {
        $templateUrl = route('vendors.import.template');
        $validator = Validator::make($request->all(), ['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $vendorImport = new VendorImport();
        try {
            $imported = $vendorImport->import($request->file('file'));
            if ($vendorImport->hasErrors()) {
                $errorFile = $vendorImport->storeErrors();
                return response()->json([
                    'success' => false,
                    'message' => 'Some vendors could not be imported.',
                    'imported' => $imported,
                    'failed' => count($vendorImport->errors()),
                    'errors' => $vendorImport->errors(),
                    'errors_url' => route('vendors.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }
            return response()->json([
                'success' => true,
                'message' => 'Vendors imported successfully.',
                'imported' => $imported,
                'failed' => 0,
                'errors' => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json(['success' => false, 'message' => 'Failed to import vendors.', 'error' => $exception->getMessage()], 500);
        }
    }

    public function export(Request $request)
    {
        return (new VendorExport())->download($request);
    }

    public function importTemplate()
    {
        return (new VendorImport())->downloadTemplate();
    }

    public function importErrors(string $filename)
    {
        $path = 'vendor-import-errors/' . basename($filename);
        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');
        return response()->download(Storage::disk('local')->path($path), 'vendor-import-errors.csv', ['Content-Type' => 'text/csv']);
    }

    public function importStructure()
    {
        return response()->json([
            'success' => true,
            'message' => 'Vendor import structure retrieved successfully.',
            'data' => [
                'headers' => ['vendor_code', 'vendor_name', 'gstin_number', 'phone_number', 'registered_address', 'brands', 'is_active'],
                'columns' => [
                    ['name' => 'vendor_code', 'type' => 'text', 'required' => true],
                    ['name' => 'vendor_name', 'type' => 'text', 'required' => true],
                    ['name' => 'gstin_number', 'type' => 'text', 'required' => true],
                    ['name' => 'phone_number', 'type' => 'text', 'required' => true],
                    ['name' => 'registered_address', 'type' => 'text', 'required' => false],
                    ['name' => 'brands', 'type' => 'text', 'required' => true, 'description' => 'Comma-separated brand names.'],
                    ['name' => 'is_active', 'type' => 'boolean', 'required' => false, 'description' => 'Optional. Defaults to true. Accepted values: true, false, yes, no, active, inactive, 1, 0.'],
                ],
                'template_url' => route('vendors.import.template'),
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
            $vendors = Vendor::withTrashed()->with('brands')
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        foreach (['vendor_code', 'vendor_name', 'gstin_number', 'phone_number'] as $column) {
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
                'message' => 'Vendors retrieved successfully.',
                'data' => $vendors->items(),
                'pagination' => [
                    'current_page' => $vendors->currentPage(), 'per_page' => $vendors->perPage(), 'total' => $vendors->total(),
                    'last_page' => $vendors->lastPage(), 'from' => $vendors->firstItem(), 'to' => $vendors->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred while retrieving vendors.', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules(), $this->messages());
        $this->validateVendorName($validator, $request->only([
            'vendor_code', 'vendor_name', 'gstin_number', 'phone_number',
        ]));
        if ($validator->fails()) return $this->validationErrorResponse($validator);
        $validated = $validator->validated();
        $brandIds = $validated['brands'];
        unset($validated['brands']);
        try {
            $validated['created_by'] = Auth::id();
            $vendor = Vendor::create($validated);
            $vendor->brands()->syncWithPivotValues($brandIds, ['created_by' => Auth::id(), 'updated_by' => Auth::id()]);
            return response()->json(['success' => true, 'message' => 'Vendor created successfully.', 'data' => $vendor->load('brands')], 201);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'create');
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to create vendor.', 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $vendor = Vendor::with('brands')->where('is_delete', false)->findOrFail($id);
            return response()->json(['success' => true, 'message' => 'Vendor retrieved successfully.', 'data' => $vendor], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Vendor not found.'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred while retrieving the vendor.', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $vendor = Vendor::where('is_delete', false)->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Vendor not found.'], 404);
        }
        $validator = Validator::make($request->all(), $this->rules($vendor->id), $this->messages());
        $this->validateVendorName($validator, $request->only([
            'vendor_code', 'vendor_name', 'gstin_number', 'phone_number',
        ]), $vendor->id);
        if ($validator->fails()) return $this->validationErrorResponse($validator);
        $validated = $validator->validated();
        $brandIds = $validated['brands'];
        unset($validated['brands']);
        try {
            $validated['updated_by'] = Auth::id();
            $vendor->update($validated);
            $vendor->brands()->syncWithPivotValues($brandIds, ['updated_by' => Auth::id()]);
            return response()->json(['success' => true, 'message' => 'Vendor updated successfully.', 'data' => $vendor->load('brands')]);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'update');
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update vendor.', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $vendor = Vendor::where('is_delete', false)->findOrFail($id);

            if ($vendor->brands()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This vendor is linked to one or more brands. Please unlink them first, then delete the vendor.',
                ], 409);
            }

            if (Employee::where('vendor_id', $vendor->id)->where('is_delete', false)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This vendor is linked to one or more employees. Please reassign or unlink them first, then delete the vendor.',
                ], 409);
            }

            $vendor->update(['is_delete' => true, 'deleted_by' => Auth::id(), 'deleted_at' => now()]);
            $vendor->delete();
            return response()->json(['success' => true, 'message' => 'Vendor deleted successfully.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Vendor not found.'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete vendor.', 'error' => $e->getMessage()], 500);
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
                'message' => 'Unauthorized. Only Super Admin or Admin can restore vendors.',
            ], 403);
        }

        $vendor = Vendor::withTrashed()->where('is_delete', true)->find($id);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted vendor not found.',
            ], 404);
        }

        $duplicateColumns = [
            'vendor_code' => 'vendor code',
            'vendor_name' => 'vendor name',
            'gstin_number' => 'GSTIN number',
            'phone_number' => 'phone number',
        ];

        foreach ($duplicateColumns as $column => $label) {
            $value = $vendor->{$column};
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $duplicate = Vendor::withTrashed()
                ->where('is_delete', false)
                ->where('id', '!=', $vendor->id)
                ->whereRaw("LOWER({$column}) = ?", [mb_strtolower(trim($value))])
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot restore. A vendor with this {$label} already exists.",
                ], 409);
            }
        }

        $vendor->update([
            'is_delete' => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active' => true,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vendor restored successfully.',
            'data' => $vendor,
        ], 200);
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'vendor_code' => ['required', 'string', 'max:255'],
            'vendor_name' => ['required', 'string', 'max:255'],
            'gstin_number' => [
                'required',
                'string',
                'max:15',
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/i',
            ],
            'phone_number' => ['required', 'string', 'max:255'],
            'registered_address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'brands' => ['required', 'array', 'min:1'],
            'brands.*' => ['integer', 'distinct', 'exists:brands,id'],
        ];
    }

    private function validateVendorName(
        ValidatorContract $validator,
        array $values,
        ?int $ignoreId = null
    ): void {
        $labels = [
            'vendor_code' => 'vendor code',
            'vendor_name' => 'vendor name',
            'gstin_number' => 'GSTIN number',
            'phone_number' => 'phone number',
        ];

        $validator->after(function ($validator) use ($values, $ignoreId, $labels): void {
            foreach ($labels as $column => $label) {
                $value = $values[$column] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }

                $existing = Vendor::withTrashed()
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

    private function messages(): array
    {
        return [
            'gstin_number.regex' => 'Please enter a valid 15-character GSTIN format (e.g. 07AAAAA0000A1Z5).',
        ];
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
        return response()->json(['success' => false, 'message' => $isDuplicate ? 'Vendor record already exists.' : 'Validation failed.', 'errors' => $errors], $isDuplicate ? 409 : 422);
    }

    private function databaseErrorResponse(QueryException $exception, string $action)
    {
        if ($exception->getCode() === '23000' || str_contains(strtolower($exception->getMessage()), 'duplicate')) {
            return response()->json(['success' => false, 'message' => 'Vendor record already exists.', 'error' => 'Duplicate vendor record.'], 409);
        }
        return response()->json(['success' => false, 'message' => "Failed to {$action} vendor.", 'error' => $exception->getMessage()], 500);
    }
}
