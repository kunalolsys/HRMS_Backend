<?php

namespace App\Http\Controllers\Api;

use App\Exports\LateDeductionSlabExport;
use App\Http\Controllers\Controller;
use App\Imports\LateDeductionSlabImport;
use App\Models\LateDeductionSlab;
use Exception;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class LateDeductionSlabController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:late_deduction_slab-view|late_deduction_slab-create|late_deduction_slab-edit|late_deduction_slab-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:late_deduction_slab-create', ['only' => ['store']]);
        $this->middleware('permission:late_deduction_slab-edit', ['only' => ['update']]);
        $this->middleware('permission:late_deduction_slab-delete', ['only' => ['destroy']]);
        $this->middleware('permission:late_deduction_slab-restore', ['only' => ['restore']]);
        $this->middleware('permission:late_deduction_slab-export', ['only' => ['export']]);
        $this->middleware('permission:late_deduction_slab-import', ['only' => ['import', 'importTemplate', 'importErrors', 'importStructure']]);
    }

    /**
     * Import late deduction slabs from a CSV file.
     */
    public function import(Request $request)
    {
        $templateUrl = route('late-deduction-slabs.import.template');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $import = new LateDeductionSlabImport();

        try {
            $imported = $import->import($request->file('file'));

            if ($import->hasErrors()) {
                $errorFile = $import->storeErrors();

                return response()->json([
                    'success'      => false,
                    'message'      => 'Some late deduction slabs could not be imported.',
                    'imported'     => $imported,
                    'failed'       => count($import->errors()),
                    'errors'       => $import->errors(),
                    'errors_url'   => route('late-deduction-slabs.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success'      => true,
                'message'      => 'Late deduction slabs imported successfully.',
                'imported'     => $imported,
                'failed'       => 0,
                'errors'       => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import late deduction slabs.',
                'error'   => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Export all late deduction slabs as a CSV file.
     */
    public function export(Request $request)
    {
        return (new LateDeductionSlabExport())->download($request);
    }

    /**
     * Download the CSV template for late deduction slab import.
     */
    public function importTemplate()
    {
        return (new LateDeductionSlabImport())->downloadTemplate();
    }

    /**
     * Download the CSV containing rejected import rows.
     */
    public function importErrors(string $filename)
    {
        $path = 'late-deduction-slab-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'late-deduction-slabs-errors.csv',
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
            'message' => 'Late deduction slab import structure retrieved successfully.',
            'data'    => [
                'headers' => ['lates_count', 'days_deducted'],
                'columns' => [
                    ['name' => 'lates_count', 'type' => 'integer', 'required' => true, 'description' => 'Threshold count of late comings.'],
                    ['name' => 'days_deducted', 'type' => 'numeric', 'required' => true, 'description' => 'Number of salary days deducted (e.g. 0.5, 1).'],
                ],
                'template_url' => route('late-deduction-slabs.import.template'),
            ],
        ]);
    }

    /**
     * Display a listing of the late deduction slabs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $page   = $request->query('page', 1);
            $limit  = $request->query('limit', 10);
            $search = mb_strtolower(trim((string) $request->query('search', '')));
            $status = strtolower(trim((string) ($request->query('status') ?? $request->query('is_active', ''))));
            $trashed = strtolower(trim((string) $request->query('trashed', '')));
            $currentUser = $request->user();
            $canViewTrashed = $currentUser
                && method_exists($currentUser, 'hasAnyRole')
                && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
            $showTrashed = $canViewTrashed
                && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');

            $slabs = LateDeductionSlab::query()
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->where('lates_count', 'like', "%{$search}%")
                            ->orWhere('days_deducted', 'like', "%{$search}%");
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->orderBy('lates_count', 'asc')
                ->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Late deduction slabs retrieved successfully.',
                'data'    => $slabs->items(),
                'pagination' => [
                    'current_page' => $slabs->currentPage(),
                    'per_page'     => $slabs->perPage(),
                    'total'        => $slabs->total(),
                    'last_page'    => $slabs->lastPage(),
                    'from'         => $slabs->firstItem(),
                    'to'           => $slabs->lastItem(),
                ],
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving late deduction slabs.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created late deduction slab in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'lates_count'   => ['required', 'integer', 'min:1'],
            'days_deducted' => ['required', 'numeric', 'min:0', 'max:99.9'],
            'is_active'     => ['sometimes', 'boolean'],
        ]);
        $this->validateLatesCount($validator, $request->input('lates_count'));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $slab = LateDeductionSlab::create($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Late deduction slab created successfully.',
                'data'    => $slab,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create late deduction slab.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified late deduction slab.
     */
    public function show(int $id): JsonResponse
    {
        $slab = LateDeductionSlab::where('is_delete', false)->find($id);

        if (!$slab) {
            return response()->json([
                'success' => false,
                'message' => 'Late deduction slab not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Late deduction slab retrieved successfully.',
            'data'    => $slab,
        ], 200);
    }

    /**
     * Update the specified late deduction slab in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $slab = LateDeductionSlab::where('is_delete', false)->find($id);

        if (!$slab) {
            return response()->json([
                'success' => false,
                'message' => 'Late deduction slab not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'lates_count'   => ['sometimes', 'required', 'integer', 'min:1'],
            'days_deducted' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99.9'],
            'is_active'     => ['sometimes', 'boolean'],
        ]);
        $this->validateLatesCount(
            $validator,
            $request->input('lates_count', (string) $slab->lates_count),
            $slab->id
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $slab->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Late deduction slab updated successfully.',
            'data'    => $slab,
        ], 200);
    }

    /**
     * Remove the specified late deduction slab from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $slab = LateDeductionSlab::where('is_delete', false)->find($id);

        if (!$slab) {
            return response()->json(['success' => false, 'message' => 'Late deduction slab not found.'], 404);
        }

        try {
            $slab->update([
                'is_delete'  => true,
                'deleted_by' => Auth::id(),
                'deleted_at' => now(),
            ]);

            return response()->json(['success' => true, 'message' => 'Late deduction slab deleted successfully.'], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete late deduction slab.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted late deduction slab.
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
                'message' => 'Unauthorized. Only Super Admin or Admin can restore late deduction slabs.',
            ], 403);
        }

        $slab = LateDeductionSlab::where('is_delete', true)->find($id);

        if (!$slab) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted late deduction slab not found.',
            ], 404);
        }

        $duplicate = LateDeductionSlab::where('is_delete', false)
            ->where('lates_count', $slab->lates_count)
            ->where('id', '!=', $slab->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. A late deduction slab with this lates count already exists.',
            ], 409);
        }

        $slab->update([
            'is_delete' => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Late deduction slab restored successfully.',
            'data' => $slab,
        ], 200);
    }

    private function validateLatesCount(
        ValidatorContract $validator,
        mixed $latesCount,
        ?int $ignoreId = null
    ): void {
        if ($latesCount === null || $latesCount === '' || !is_numeric($latesCount)) {
            return;
        }

        $validator->after(function ($validator) use ($latesCount, $ignoreId): void {
            $existing = LateDeductionSlab::where('lates_count', (int) $latesCount)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->orderBy('is_delete', 'asc')
                ->orderByDesc('id')
                ->first();

            if (!$existing) {
                return;
            }

            if ($existing->is_delete) {
                $message = 'The lates count already exists in deleted stage.';
            } elseif (!$existing->is_active) {
                $message = 'The lates count already exists in deactivated stage.';
            } else {
                $message = 'The lates count has already been taken.';
            }

            $validator->errors()->add('lates_count', $message);
        });
    }

    private function validationErrorResponse(ValidatorContract $validator): JsonResponse
    {
        $errors = $validator->errors()->toArray();
        $isDuplicate = collect($errors)->flatten()->contains(function ($message): bool {
            $message = strtolower($message);

            return str_contains($message, 'already been taken')
                || str_contains($message, 'already exists in deleted stage')
                || str_contains($message, 'already exists in deactivated stage');
        });

        return response()->json([
            'success' => false,
            'message' => $isDuplicate ? 'Late deduction slab already exists.' : 'Validation failed.',
            'errors' => $errors,
        ], $isDuplicate ? 409 : 422);
    }
}