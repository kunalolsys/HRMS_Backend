<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HandlesDuplicateNameValidation;
use App\Exports\BrandExport;
use App\Imports\BrandImport;
use App\Models\Brand;
use App\Models\Employee;
use App\Models\Division;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;
use Spatie\Permission\Models\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;


class BrandController extends Controller
{
    use HandlesDuplicateNameValidation;

    function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:brand-view|brand-create|brand-edit|brand-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:brand-create', ['only' => ['store']]);
        $this->middleware('permission:brand-edit', ['only' => ['update']]);
        $this->middleware('permission:brand-delete', ['only' => ['destroy']]);
        $this->middleware('permission:brand-restore', ['only' => ['restore']]);
        $this->middleware('permission:brand-export', ['only' => ['export']]);
        $this->middleware('permission:brand-import', ['only' => ['import', 'importTemplate', 'importErrors', 'importStructure']]);
    }
    /**
     * Import brands from a CSV file.
     */
    public function import(Request $request)
    {
        $templateUrl = route('brands.import.template');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $brandImport = new BrandImport();

        try {
            $imported = $brandImport->import($request->file('file'));

            if ($brandImport->hasErrors()) {
                $errorFile = $brandImport->storeErrors();

                return response()->json([
                    'success' => false,
                    'message' => 'Some brands could not be imported.',
                    'imported' => $imported,
                    'failed' => count($brandImport->errors()),
                    'errors' => $brandImport->errors(),
                    'errors_url' => route('brands.import.errors', [
                        'filename' => basename($errorFile),
                    ]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Brands imported successfully.',
                'imported' => $imported,
                'failed' => 0,
                'errors' => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import brands.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Export all brands as a CSV file.
     */
    public function export(Request $request)
    {
        return (new BrandExport())->download($request);
    }

    /**
     * Download the CSV template used by the brand import endpoint.
     */
    public function importTemplate()
    {
        return (new BrandImport())->downloadTemplate();
    }

    /**
     * Download the CSV containing rejected brand import rows.
     */
    public function importErrors(string $filename)
    {
        $path = 'brand-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'brand-import-errors.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    /**
     * Return the fields required by the brand import file.
     */
    public function importStructure()
    {
        return response()->json([
            'success' => true,
            'message' => 'Brand import structure retrieved successfully.',
            'data' => [
                'headers' => ['brand_code', 'brand_name', 'divisions', 'is_active'],
                'columns' => [
                    [
                        'name' => 'brand_code',
                        'type' => 'text',
                        'required' => true,
                    ],
                    [
                        'name' => 'brand_name',
                        'type' => 'text',
                        'required' => true,
                    ],
                    [
                        'name' => 'divisions',
                        'type' => 'text',
                        'required' => true,
                        'description' => 'Comma-separated division names.',
                    ],
                    [
                        'name' => 'is_active',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Optional. Defaults to true. Accepted values: true, false, yes, no, active, inactive, 1, 0.',
                    ],
                ],
                'template_url' => route('brands.import.template'),
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

            $query = Brand::withTrashed()
                ->with('divisions')
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->whereRaw('LOWER(brand_code) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(brand_name) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $isActive = in_array($status, ['active', '1', 'true'], true);
                    $query->where('is_active', $isActive);
                })
                ->where('is_delete', $showTrashed)
                ->orderByDesc('id');

            $brands = $query
                ->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Brands retrieved successfully.',
                'data' => $brands->items(),
                'pagination' => [
                    'current_page' => $brands->currentPage(),
                    'per_page'     => $brands->perPage(),
                    'total'        => $brands->total(),
                    'last_page'    => $brands->lastPage(),
                    'from'         => $brands->firstItem(),
                    'to'           => $brands->lastItem(),
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving brands.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'brand_code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('brands', 'brand_code')->where(fn ($query) => $query->where('is_delete', false)),
            ],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'divisions' => ['required', 'array', 'min:1'],
            'divisions.*' => ['integer', 'distinct', 'exists:divisions,id'],
        ]);

        $this->addNameDuplicateValidation(
            $validator,
            Brand::class,
            'brand_name',
            $request->input('brand_name'),
            null,
            'The brand name has already been taken.');
        $this->validateDivisions($validator, $request->input('divisions'));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validated = $validator->validated();

        try {
            $divisionIds = $validated['divisions'] ?? [];
            unset($validated['divisions']);
            $brand = Brand::create($validated);

            if ($request->has('divisions')) {
                $brand->divisions()->sync($divisionIds);
            }

            return response()->json([
                'success' => true,
                'message' => 'Brand created successfully.',
                'data' => $brand->load('divisions')
            ], 201);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Brand code already exists.',
                    'error' => 'Duplicate brand record.'
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create brand.',
                'error' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create brand.',
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
            $brand = Brand::with('divisions')->findOrFail($id);
            return response()->json([
                'success' => true,
                'message' => 'Brand retrieved successfully.',
                'data' => $brand
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Brand not found.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving the brand.',
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
            $brand = Brand::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Brand not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'brand_code' => ['nullable', 'string', 'max:255', Rule::unique('brands', 'brand_code')->ignore($brand->id)],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'divisions' => ['required', 'array', 'min:1'],
            'divisions.*' => ['integer', 'distinct', 'exists:divisions,id'],
        ]);

        $this->addNameDuplicateValidation(
            $validator,
            Brand::class,
            'brand_name',
            $request->input('brand_name'),
            $brand->id,
            'The brand name has already been taken.');
        $this->validateDivisions($validator, $request->input('divisions'));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validated = $validator->validated();

        try {
            $divisionIds = $validated['divisions'] ?? [];
            unset($validated['divisions']);

            $brand->update($validated);

            if ($request->has('divisions')) {
                $brand->divisions()->sync($divisionIds);
            }

            return response()->json([
                'success' => true,
                'message' => 'Brand updated successfully.',
                'data' => $brand->load('divisions')
            ], 200);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Brand code already exists.',
                    'error' => 'Duplicate brand record.'
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to update brand.',
                'error' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update brand.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $brand = Brand::where('is_delete', false)->find($id);
            if (!$brand) {
                return response()->json(['success' => false, 'message' => 'Brand not found.'], 404);
            }

            if ($brand->divisions()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This brand is linked to one or more departments/divisions. Please unlink them first, then delete the brand.',
                ], 409);
            }

            if ($brand->vendors()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This brand is linked to one or more vendors. Please unlink them first, then delete the brand.',
                ], 409);
            }

            if (Employee::where('brand_id', $brand->id)->where('is_delete', false)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This brand is linked to one or more employees. Please reassign or unlink them first, then delete the brand.',
                ], 409);
            }

            $brand->update([
                'is_delete' => true,
                'deleted_by' => Auth::id(),
            ]);

            $brand->delete();

            return response()->json(['success' => true, 'message' => 'Brand deleted successfully.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete Brand: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Restore a soft-deleted brand.
     */
    public function restore(int $id)
    {
        if (!$this->canRestoreBrands()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only Super Admin or Admin can restore brands.',
            ], 403);
        }

        $brand = Brand::withTrashed()->where('is_delete', true)->find($id);

        if (!$brand) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted brand not found.',
            ], 404);
        }

        $duplicateCode = $brand->brand_code !== null
            && Brand::where('is_delete', false)
                ->where('brand_code', $brand->brand_code)
                ->where('id', '!=', $brand->id)
                ->exists();

        if ($duplicateCode) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. A brand with this brand code already exists.',
            ], 409);
        }

        $duplicateName = $brand->brand_name !== null
            && Brand::where('is_delete', false)
                ->whereRaw('LOWER(brand_name) = ?', [mb_strtolower(trim($brand->brand_name))])
                ->where('id', '!=', $brand->id)
                ->exists();

        if ($duplicateName) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. A brand with this brand name already exists.',
            ], 409);
        }

        try {
            DB::transaction(function () use ($brand): void {
                $brand->restore();
                $brand->update([
                    'is_delete' => false,
                    'deleted_by' => null,
                    'updated_by' => Auth::id(),
                ]);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot restore. A duplicate brand already exists.',
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to restore brand.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Brand restored successfully.',
            'data' => $brand->load('divisions'),
        ], 200);
    }

    private function validationErrorResponse(ValidatorContract $validator)
    {
        $errors = $validator->errors()->toArray();
        $duplicateMessages = collect($errors)->flatten();
        $isDuplicate = $duplicateMessages
            ->contains(fn ($message) => str_contains(strtolower($message), 'already been taken'));
        $hasBrandNameDuplicate = collect($errors['brand_name'] ?? [])
            ->contains(fn ($message) => str_contains(strtolower($message), 'already been taken'));

        return response()->json([
            'success' => false,
            'message' => $hasBrandNameDuplicate
                ? 'Brand name already exists.'
                : ($isDuplicate ? 'Brand code already exists.' : 'Validation failed.'),
            'errors' => $errors
        ], $isDuplicate ? 409 : 422);
    }

    private function validateDivisions(ValidatorContract $validator, mixed $divisionIds): void
    {
        if (!is_array($divisionIds)) {
            return;
        }

        $validIds = [];
        foreach ($divisionIds as $divisionId) {
            $validatedId = filter_var($divisionId, FILTER_VALIDATE_INT);
            if ($validatedId !== false && $validatedId > 0) {
                $validIds[] = $validatedId;
            }
        }

        $divisions = Division::whereIn('id', $validIds)->get()->keyBy('id');

        foreach ($validIds as $divisionId) {
            $division = $divisions->get($divisionId);
            if (!$division) {
                continue;
            }

            if ($division->is_delete) {
                $validator->errors()->add('divisions', "The selected division {$division->name} is deleted.");
            } elseif (!$division->is_active) {
                $validator->errors()->add('divisions', "The selected division {$division->name} is inactive and cannot be assigned.");
            }
        }
    }

    private function canRestoreBrands(): bool
    {
        $currentUser = Auth::user();

        if (!$currentUser || !method_exists($currentUser, 'getRoleNames')) {
            return false;
        }

        return $currentUser->getRoleNames()
            ->map(fn (string $role): string => mb_strtolower(trim($role)))
            ->intersect(['super admin', 'admin'])
            ->isNotEmpty();
    }

    private function isDuplicateException(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }
}