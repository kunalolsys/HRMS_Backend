<?php

namespace App\Http\Controllers\Api;

use App\Exports\MinimumWageMasterExport;
use App\Http\Controllers\Controller;
use App\Imports\MinimumWageMasterImport;
use App\Models\MinimumWageMaster;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MinimumWageMasterController extends Controller
{
    /**
     * Display a listing of minimum wage masters.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $page   = $request->query('page', 1);
            $limit  = $request->query('limit', 10);
            $search = mb_strtolower(trim((string) $request->query('search', '')));
            $status = strtolower(trim((string) $request->query('status', '')));
            $state  = trim((string) $request->query('state_code', ''));
            $trashed = strtolower(trim((string) $request->query('trashed', '')));
            $currentUser = $request->user();
            $canViewTrashed = $currentUser
                && method_exists($currentUser, 'hasAnyRole')
                && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
            $showTrashed = $canViewTrashed
                && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');

            $wages = MinimumWageMaster::with(['creator:id,name', 'updater:id,name'])
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->whereRaw('LOWER(state_code) LIKE ?', ["%{$search}%"])
                          ->orWhereRaw('LOWER(skill_category) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->when($state !== '', fn ($query) => $query->where('state_code', $state))
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->orderByDesc('id')
                ->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success'    => true,
                'message'    => 'Minimum wages retrieved successfully.',
                'data'       => $wages->items(),
                'pagination' => [
                    'current_page' => $wages->currentPage(),
                    'per_page'     => $wages->perPage(),
                    'total'        => $wages->total(),
                    'last_page'    => $wages->lastPage(),
                    'from'         => $wages->firstItem(),
                    'to'           => $wages->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving minimum wages.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created minimum wage master in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());
        $this->validateMinimumWage($validator, $request);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $validatedData               = $validator->validated();
            $validatedData['created_by'] = Auth::id();
            $validatedData['is_active']  = $validatedData['is_active'] ?? true;
            $validatedData['is_delete']  = false;

            $wage = MinimumWageMaster::create($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Minimum wage created successfully.',
                'data'    => $wage->load(['creator:id,name', 'updater:id,name']),
            ], 201);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Minimum wage record already exists.',
                    'error'   => 'Duplicate minimum wage entry.',
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create minimum wage.',
                'error'   => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create minimum wage.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified minimum wage master.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $wage = MinimumWageMaster::with(['creator:id,name', 'updater:id,name'])
                ->where('is_delete', false)
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Minimum wage retrieved successfully.',
                'data'    => $wage,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Minimum wage not found.'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified minimum wage master in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $wage = MinimumWageMaster::where('is_delete', false)->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Minimum wage not found.'], 404);
        }

        $validator = Validator::make($request->all(), $this->rules());
        $this->validateMinimumWage($validator, $request, $wage->id);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $validatedData               = $validator->validated();
            $validatedData['updated_by'] = Auth::id();

            $wage->update($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Minimum wage updated successfully.',
                'data'    => $wage->load(['creator:id,name', 'updater:id,name']),
            ], 200);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Minimum wage record already exists.',
                    'error'   => 'Duplicate minimum wage entry.',
                ], 409);
            }

            return response()->json(['success' => false, 'message' => 'Failed to update minimum wage.', 'error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update minimum wage.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified minimum wage master from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $wage = MinimumWageMaster::where('is_delete', false)->findOrFail($id);
            $wage->update([
                'is_delete'  => true,
                'deleted_by' => Auth::id(),
                'deleted_at' => now(),
            ]);

            return response()->json(['success' => true, 'message' => 'Minimum wage deleted successfully.'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Minimum wage not found.'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete minimum wage: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Restore a soft-deleted minimum wage master.
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
                'message' => 'Unauthorized. Only Super Admin or Admin can restore minimum wages.',
            ], 403);
        }

        $wage = MinimumWageMaster::where('is_delete', true)->find($id);

        if (!$wage) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted minimum wage not found.',
            ], 404);
        }

        $duplicate = MinimumWageMaster::where('is_delete', false)
            ->whereRaw('LOWER(state_code) = ?', [mb_strtolower(trim($wage->state_code))])
            ->whereRaw('LOWER(skill_category) = ?', [mb_strtolower(trim($wage->skill_category))])
            ->whereDate('effective_from', $wage->effective_from)
            ->where('id', '!=', $wage->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. A minimum wage record for this state, skill category, and effective date already exists.',
            ], 409);
        }

        $wage->update([
            'is_delete' => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active' => true,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Minimum wage restored successfully.',
            'data' => $wage->load(['creator:id,name', 'updater:id,name']),
        ], 200);
    }

    public function import(Request $request): JsonResponse
    {
        $templateUrl = route('minimum-wage-masters.import.template');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            $validationErrors = $validator->errors()->all();

            return response()->json([
                'success' => false,
                'message' => 'Validation failed: ' . implode(' ', $validationErrors),
                'error'   => implode(' ', $validationErrors),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $import = new MinimumWageMasterImport();

        try {
            $imported = $import->import($request->file('file'));

            if ($import->hasErrors()) {
                $errorFile = $import->storeErrors();
                $errorDetails = collect($import->errors())
                    ->map(function (array $failure): string {
                        $messages = implode(' ', (array) ($failure['errors'] ?? []));

                        return $messages === ''
                            ? ''
                            : 'Row ' . ($failure['row'] ?? '?') . ': ' . $messages;
                    })
                    ->filter()
                    ->values();
                $errorSummary = $errorDetails->implode(' | ');

                return response()->json([
                    'success'      => false,
                    'message'      => $errorDetails->isNotEmpty()
                        ? 'Some minimum wages could not be imported. ' . $errorDetails->first()
                        : 'Some minimum wages could not be imported.',
                    'error'        => $errorSummary,
                    'imported'     => $imported,
                    'failed'       => count($import->errors()),
                    'errors'       => $import->errors(),
                    'errors_url'   => route('minimum-wage-masters.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success'      => true,
                'message'      => 'Minimum wages imported successfully.',
                'imported'     => $imported,
                'failed'       => 0,
                'errors'       => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import minimum wages: ' . $exception->getMessage(),
                'error'   => $exception->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request)
    {
        return (new MinimumWageMasterExport())->download($request);
    }

    public function importTemplate()
    {
        return (new MinimumWageMasterImport())->downloadTemplate();
    }

    public function importErrors(string $filename)
    {
        $path = 'minimum-wage-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(Storage::disk('local')->path($path), 'minimum-wage-errors.csv', ['Content-Type' => 'text/csv']);
    }

    public function importStructure(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Minimum wage import structure retrieved successfully.',
            'data'    => [
                'headers' => ['state_code', 'skill_category', 'basic_minimum_wage', 'vda_amount', 'effective_from', 'effective_to', 'is_active'],
                'columns' => [
                    ['name' => 'state_code', 'type' => 'text', 'required' => true, 'description' => 'State code (e.g. DL, HR).'],
                    ['name' => 'skill_category', 'type' => 'text', 'required' => true, 'description' => 'Unskilled, Semi-Skilled, Skilled, etc.'],
                    ['name' => 'basic_minimum_wage', 'type' => 'numeric', 'required' => true, 'description' => 'Basic minimum wage amount.'],
                    ['name' => 'vda_amount', 'type' => 'numeric', 'required' => false, 'description' => 'Variable dearness allowance amount.'],
                    ['name' => 'effective_from', 'type' => 'date', 'required' => true, 'description' => 'YYYY-MM-DD.'],
                    ['name' => 'effective_to', 'type' => 'date', 'required' => false, 'description' => 'YYYY-MM-DD.'],
                    ['name' => 'is_active', 'type' => 'boolean', 'required' => false, 'description' => '1, 0, true, false.'],
                ],
                'template_url' => route('minimum-wage-masters.import.template'),
            ],
        ]);
    }

    private function rules(): array
    {
        return [
            'state_code'         => ['required', 'string', 'max:50'],
            'skill_category'     => ['required', 'string', 'max:100'],
            'basic_minimum_wage' => ['required', 'numeric', 'min:0'],
            'vda_amount'         => ['nullable', 'numeric', 'min:0'],
            'effective_from'     => ['required', 'date'],
            'effective_to'       => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active'          => ['sometimes', 'boolean'],
        ];
    }

    private function validateMinimumWage(
        ValidatorContract $validator,
        Request $request,
        ?int $ignoreId = null
    ): void
    {
        $validator->after(function ($validator) use ($request, $ignoreId): void {
            $stateCode = trim((string) $request->input('state_code'));

            if ($stateCode !== '') {
                $existing = MinimumWageMaster::whereRaw('LOWER(state_code) = ?', [mb_strtolower($stateCode)])
                    ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                    ->orderBy('is_delete', 'asc')
                    ->orderByDesc('id')
                    ->first();

                if ($existing) {
                    if ($existing->is_delete) {
                        $message = 'A wage record for this state code already exists in deleted stage.';
                    } elseif (!$existing->is_active) {
                        $message = 'A wage record for this state code already exists in deactivated stage.';
                    } else {
                        $message = 'A wage record for this state code already exists.';
                    }

                    $validator->errors()->add('state_code', $message);
                }
            }
        });
    }

    private function validationErrorResponse(ValidatorContract $validator): JsonResponse
    {
        $errors      = $validator->errors()->toArray();
        $isDuplicate = collect($errors)->flatten()->contains(function ($message): bool {
            $message = strtolower($message);

            return str_contains($message, 'already exists')
                || str_contains($message, 'already been taken');
        });

        return response()->json([
            'success' => false,
            'message' => $isDuplicate ? 'Minimum wage record already exists.' : 'Validation failed.',
            'errors'  => $errors,
        ], $isDuplicate ? 409 : 422);
    }

    private function isDuplicateException(QueryException $exception): bool
    {
        return $exception->getCode() === '23000' || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }
}