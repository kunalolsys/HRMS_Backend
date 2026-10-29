<?php

namespace App\Http\Controllers\Api;

use App\Exports\EmployeeExport;
use App\Http\Controllers\Controller;
use App\Imports\EmployeeImport;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Designation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{

    private const UNIQUE_FIELDS = [
        'employee_code' => 'employee code',
        'mobile_number' => 'mobile number',
        'email_id' => 'email address',
        'pan_number' => 'PAN number',
        'aadhar_number' => 'Aadhaar number',
        'permanent_unique_id' => 'Permanent Unique ID',
        'telegram_username' => 'Telegram username',
        'chat_id' => 'chat ID',
    ];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:employee-view|employee-create|employee-edit|employee-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:employee-create', ['only' => ['store']]);
        $this->middleware('permission:employee-edit', ['only' => ['update']]);
        $this->middleware('permission:employee-delete', ['only' => ['destroy']]);
        $this->middleware('permission:employee-restore', ['only' => ['restore']]);
        $this->middleware('permission:employee-export', ['only' => ['export']]);
        $this->middleware('permission:employee-import', ['only' => ['import', 'importTemplate', 'importErrors', 'importStructure']]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $page         = $request->query('page', 1);
            $limit        = $request->query('limit', 10);
            $search       = mb_strtolower(trim((string) $request->query('search', '')));
            $status       = strtolower(trim((string) ($request->query('is_active') ?? $request->query('status', ''))));
            $locationId   = $request->query('location_id');
            $brandId      = $request->query('brand_id');
            $vendorId     = $request->query('vendor_id');
            $employeeType = mb_strtolower(trim((string) $request->query('employee_type', '')));
            $trashed       = strtolower(trim((string) $request->query('trashed', '')));
            $currentUser   = $request->user();
            $canViewTrashed = $currentUser
                && method_exists($currentUser, 'hasAnyRole')
                && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
            $showTrashed = $canViewTrashed
                && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');

            $query = Employee::withTrashed()->with([
                'company:id,company_name', 'location:id,location_name', 'division:id,name',
                'designation:id,name', 'shift:id,shift_name', 'brand:id,brand_name', 
                'vendor:id,vendor_name', 'reportingDmUser:id,name', 'reportingSmUser:id,name'
            ])
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->whereRaw('LOWER(employee_name) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(employee_code) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(email_id) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->when(!empty($locationId), function ($query) use ($locationId): void {
                    $query->where('location_id', $locationId);
                })
                ->when(!empty($brandId), function ($query) use ($brandId): void {
                    $query->where('brand_id', $brandId);
                })
                ->when(!empty($vendorId), function ($query) use ($vendorId): void {
                    $query->where('vendor_id', $vendorId);
                })
                ->when($employeeType !== '', function ($query) use ($employeeType): void {
                    $query->whereRaw('LOWER(employee_type) = ?', [$employeeType]);
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $isActive = in_array($status, ['active', '1', 'true'], true);
                    $query->where('is_active', $isActive);
                })
                ->where('is_delete', $showTrashed)
                ->orderByDesc('id');

            $employees = $query->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success'    => true,
                'message'    => 'Employees retrieved successfully.',
                'data'       => $employees->items(),
                'pagination' => [
                    'current_page' => $employees->currentPage(),
                    'per_page'     => $employees->perPage(),
                    'total'        => $employees->total(),
                    'last_page'    => $employees->lastPage(),
                    'from'         => $employees->firstItem(),
                    'to'           => $employees->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving employees.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $rawAadhar = $request->input('aadhar_no')
            ?? $request->input('aadhar_number')
            ?? $request->input('adhaar_no')
            ?? $request->input('aadhar')
            ?? $request->input('permanent_unique_id');

        if (!empty($rawAadhar)) {
            $cleanAadhar = preg_replace('/\D/', '', (string) $rawAadhar);
            if (strlen($cleanAadhar) === 12) {
                $request->merge(['aadhar_number' => $cleanAadhar]);
            }
        }

        if ($request->filled('employee_code') && !empty($rawAadhar)) {
            $cleanAadhar = preg_replace('/\D/', '', (string) $rawAadhar);
            $last4Aadhar = strlen($cleanAadhar) >= 4 ? substr($cleanAadhar, -4) : substr(trim((string) $rawAadhar), -4);
            $code = mb_strtoupper(trim((string) $request->input('employee_code')));
            $request->merge(['permanent_unique_id' => $code .'-'. $last4Aadhar]);
        }

        $employeeType = $request->input('employee_type');
        $brandId = $request->input('brand_id');
        $validator = Validator::make(
            $request->all(),
            $this->getValidationRules($employeeType, $brandId),
            $this->getValidationMessages()
        );
        self::validateEmployeeUniqueValues($validator, $request->all());

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $employee = DB::transaction(function () use ($validator, $rawAadhar) {
                $validatedData = $validator->validated();

                if (empty($validatedData['employee_code'])) {
                    $validatedData['employee_code'] = self::generateEmployeeCode();
                }

                if (!empty($rawAadhar)) {
                    $cleanAadhar = preg_replace('/\D/', '', (string) $rawAadhar);
                    $last4Aadhar = strlen($cleanAadhar) >= 4 ? substr($cleanAadhar, -4) : substr(trim((string) $rawAadhar), -4);
                    $code = mb_strtoupper(trim((string) $validatedData['employee_code']));
                    $validatedData['permanent_unique_id'] = $code .'-'. $last4Aadhar;
                }

                $validatedData['is_active'] = $validatedData['is_active'] ?? true;
                $validatedData['is_delete'] = $validatedData['is_delete'] ?? false;
                $validatedData['created_by'] = Auth::id();

                return Employee::create($validatedData);
            }, 5);

            return response()->json([
                'success' => true,
                'message' => 'Employee created successfully.',
                'data' => $employee
            ], 201);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee record already exists.',
                    'error'   => 'Duplicate employee record.'
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create employee.',
                'error'   => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to create employee.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Employee $employee)
    {
        if ($employee->is_delete) {
            return response()->json(['success' => false, 'message' => 'Employee not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Employee retrieved successfully.',
            'data' => $employee->load([
                'company:id,company_name', 'location:id,location_name', 'division:id,name',
                'designation:id,name', 'shift:id,shift_name', 'brand:id,brand_name',
                'vendor:id,vendor_name', 'reportingDmUser:id,name', 'reportingSmUser:id,name'
            ])
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Employee $employee)
    {
        if ($employee->is_delete) {
            return response()->json(['success' => false, 'message' => 'Employee not found.'], 404);
        }

        if ($request->has('aadhar_number') && !empty($request->input('aadhar_number'))) {
            $cleanAadhar = preg_replace('/\D/', '', (string) $request->input('aadhar_number'));
            $request->merge(['aadhar_number' => $cleanAadhar]);
        }

        $employeeType = $request->input('employee_type', $employee->employee_type);
        $brandId = $request->has('brand_id') ? $request->input('brand_id') : $employee->brand_id;
        $validator = Validator::make(
            $request->all(),
            $this->getValidationRules($employeeType, $brandId),
            $this->getValidationMessages()
        );
        self::validateEmployeeUniqueValues($validator, $request->all(), $employee->id);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $validatedData = $validator->validated();
            $validatedData['updated_by'] = Auth::id();

            $employee->update($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Employee updated successfully.',
                'data' => $employee
            ], 200);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee record already exists.',
                    'error'   => 'Duplicate employee record.'
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to update employee.',
                'error'   => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update employee.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $employee = $id instanceof Employee ? $id : Employee::findOrFail($id);
            if ($employee->is_delete) {
                return response()->json(['success' => false, 'message' => 'Employee not found.'], 404);
            }

            $employee->update([
                'is_delete'  => true,
                'deleted_by' => Auth::id(),
                'deleted_at' => now(),
            ]);

            $employee->delete();

            return response()->json(['success' => true, 'message' => 'Employee deleted successfully.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Employee not found.'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete employee: ' . $e->getMessage()], 500);
        }
    }

    public function import(Request $request)
    {
        $templateUrl = route('employees.import.template');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $import = new EmployeeImport();
        try {
            $imported = $import->import($request->file('file'));
            if ($import->hasErrors()) {
                $errorFile = $import->storeErrors();
                return response()->json([
                    'success'      => false,
                    'message'      => 'Some employees could not be imported.',
                    'imported'     => $imported,
                    'failed'       => count($import->errors()),
                    'errors'       => $import->errors(),
                    'errors_url'   => route('employees.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }
            return response()->json([
                'success'      => true,
                'message'      => 'Employees imported successfully.',
                'imported'     => $imported,
                'failed'       => 0,
                'errors'       => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee record already exists.',
                    'error'   => 'Duplicate employee record.',
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to import employees.',
                'error'   => $e->getMessage(),
            ], 500);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to import employees.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Return the schema/structure expected by the employee import CSV.
     */
    public function importStructure()
    {
        $headers = [
            'employee_name', 'mobile_number', 'email_id', 'pan_number',
            'aadhar_number', 'company_name', 'location_name', 'division_name', 'designation_name',
            'shift_name', 'brand_name', 'vendor_name', 'blood_group', 'gender', 'age', 'job_status',
            'ecode', 'birthday_date', 'status', 'mode', 'father_name', 'current_address',
            'permanent_address', 'marital_status', 'spouse_name', 'anniversary_date',
            'basic_salary', 'date_of_join', 'date_of_leave', 'pf_number', 'uan_number',
            'esi_number', 'bank_name', 'account_number', 'ifsc_number', 'weekly_off',
            'employee_type', 'emergency_contact_person_name',
            'emergency_contact_person_relation', 'emergency_contact_person_phone_number',
            'reporting_dm', 'reporting_sm', 'is_active',
        ];

        return response()->json([
            'success' => true,
            'message' => 'Employee import structure retrieved successfully.',
            'data'    => [
                'headers' => $headers,
                'columns' => [
                    ['name' => 'employee_code', 'type' => 'text', 'required' => false, 'description' => 'Optional. Auto-generated if left blank.'],
                    ['name' => 'employee_name', 'type' => 'text', 'required' => true, 'description' => 'Full name of the employee.'],
                    ['name' => 'mobile_number', 'type' => 'text', 'required' => true, 'description' => 'Unique contact number.'],
                    ['name' => 'email_id', 'type' => 'email', 'required' => true, 'description' => 'Unique email address.'],
                    ['name' => 'pan_number', 'type' => 'text', 'required' => false, 'description' => '10-character PAN number.'],
                    ['name' => 'aadhar_number', 'type' => 'text', 'required' => false, 'description' => '12-digit Aadhaar number.'],
                   // ['name' => 'permanent_unique_id', 'type' => 'text', 'required' => false, 'description' => 'Aadhaar / Unique ID.'],
                    ['name' => 'company_name', 'type' => 'text', 'required' => true, 'description' => 'Company name, code, or ID.'],
                    ['name' => 'location_name', 'type' => 'text', 'required' => true, 'description' => 'Location name, code, or ID.'],
                    ['name' => 'division_name', 'type' => 'text', 'required' => true, 'description' => 'Division/Section name or ID.'],
                    ['name' => 'designation_name', 'type' => 'text', 'required' => true, 'description' => 'Designation name, code, or ID.'],
                    ['name' => 'shift_name', 'type' => 'text', 'required' => true, 'description' => 'Shift name, code, or ID.'],
                    ['name' => 'brand_name', 'type' => 'text', 'required' => false, 'description' => 'Brand name, code, or ID. Required if employee_type is Brand-Type-A or Brand-Type-B.'],
                    ['name' => 'vendor_name', 'type' => 'text', 'required' => false, 'description' => 'Vendor name, code, or ID. Required if employee_type is Brand-Type-A or Brand-Type-B.'],
                    ['name' => 'blood_group', 'type' => 'text', 'required' => false, 'description' => 'Blood group (e.g. A+, O+, etc.).'],
                    ['name' => 'gender', 'type' => 'text', 'required' => true, 'description' => 'Gender (Male, Female, etc.).'],
                    ['name' => 'age', 'type' => 'integer', 'required' => true, 'description' => 'Age in years.'],
                    ['name' => 'job_status', 'type' => 'text', 'required' => false, 'description' => 'Current job status.'],
                    ['name' => 'ecode', 'type' => 'text', 'required' => false, 'description' => 'Secondary employee code / punch code.'],
                    ['name' => 'birthday_date', 'type' => 'date', 'required' => false, 'description' => 'Format: YYYY-MM-DD.'],
                    ['name' => 'status', 'type' => 'text', 'required' => false, 'description' => 'Employment status.'],
                    ['name' => 'mode', 'type' => 'text', 'required' => false, 'description' => 'Employment mode (e.g., On-roll, Contract).'],
                    ['name' => 'father_name', 'type' => 'text', 'required' => true, 'description' => "Father's name."],
                    ['name' => 'current_address', 'type' => 'text', 'required' => true, 'description' => 'Current residential address.'],
                    ['name' => 'permanent_address', 'type' => 'text', 'required' => true, 'description' => 'Permanent residential address.'],
                    ['name' => 'marital_status', 'type' => 'text', 'required' => true, 'description' => 'Single, Married, etc.'],
                    ['name' => 'spouse_name', 'type' => 'text', 'required' => false, 'description' => "Spouse's full name."],
                    ['name' => 'anniversary_date', 'type' => 'date', 'required' => false, 'description' => 'Format: YYYY-MM-DD.'],
                    ['name' => 'basic_salary', 'type' => 'numeric', 'required' => false, 'description' => 'Monthly basic salary amount.'],
                    ['name' => 'date_of_join', 'type' => 'date', 'required' => false, 'description' => 'Format: YYYY-MM-DD.'],
                    ['name' => 'date_of_leave', 'type' => 'date', 'required' => false, 'description' => 'Format: YYYY-MM-DD (must be on or after date_of_join).'],
                    ['name' => 'pf_number', 'type' => 'text', 'required' => false, 'description' => 'Provident fund number (max 22 chars).'],
                    ['name' => 'uan_number', 'type' => 'text', 'required' => false, 'description' => '12-digit UAN number.'],
                    ['name' => 'esi_number', 'type' => 'text', 'required' => false, 'description' => '17-digit ESI number.'],
                    ['name' => 'bank_name', 'type' => 'text', 'required' => false, 'description' => 'Bank name.'],
                    ['name' => 'account_number', 'type' => 'text', 'required' => false, 'description' => 'Bank account number (max 20 digits).'],
                    ['name' => 'ifsc_number', 'type' => 'text', 'required' => false, 'description' => '11-character bank IFSC code.'],
                    ['name' => 'weekly_off', 'type' => 'text', 'required' => true, 'description' => 'Weekly off day (e.g. Sunday, Monday).'],
                   // ['name' => 'brand_staff', 'type' => 'boolean', 'required' => true, 'description' => 'Accepted values: true, false, yes, no, 1, 0.'],
                    ['name' => 'employee_type', 'type' => 'text', 'required' => true, 'description' => 'Own-payroll/Brand-Type-A/Brand-Type-B.'],
                    ['name' => 'emergency_contact_person_name', 'type' => 'text', 'required' => false, 'description' => 'Emergency contact person name.'],
                    ['name' => 'emergency_contact_person_relation', 'type' => 'text', 'required' => false, 'description' => 'Relation with emergency contact.'],
                    ['name' => 'emergency_contact_person_phone_number', 'type' => 'text', 'required' => false, 'description' => 'Emergency contact phone number.'],
                    ['name' => 'reporting_dm', 'type' => 'text/integer', 'nullable' => false, 'description' => 'Reporting DM user ID, name, or email.'],
                    ['name' => 'reporting_sm', 'type' => 'text/integer', 'nullable' => false, 'description' => 'Reporting SM user ID, name, or email.'],
                    ['name' => 'is_active', 'type' => 'boolean', 'required' => false, 'description' => 'Accepted values: true, false, yes, no, active, inactive, 1, 0. Defaults to true.'],
                ],
                'template_url' => route('employees.import.template'),
            ],
        ]);
    }

    public function export(Request $request)
    {
        return (new EmployeeExport())->download($request);
    }

    public function importTemplate()
    {
        return (new EmployeeImport())->downloadTemplate();
    }

    public function importErrors(string $filename)
    {
        $path = 'employee-import-errors/' . basename($filename);
        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');
        return response()->download(Storage::disk('local')->path($path), 'employee-import-errors.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Synchronize employees (create or update) from an external API.
     */
    public function sync(Request $request)
    {
        $apiUrl = config('services.employee_api.url'); // Reads from config/services.php
        $apiKey = config('services.employee_api.key'); // Reads from config/services.php

        if (!$apiUrl || !$apiKey) {
            return response()->json(['success' => false, 'message' => 'Employee API URL or Key is not configured.'], 500);
        }

        try {
            // Use Laravel's Http client (which uses cURL) to get the data
            $response = Http::withToken($apiKey)->post($apiUrl);

            if ($response->failed()) {
                return response()->json(['success' => false, 'message' => 'Failed to fetch data from the external API.', 'error' => $response->body()], $response->status());
            }

            $employeesData = $response->json();

            // Validate that the response has the 'employees' key and it's an array
            if (!is_array($employeesData)) {
                return response()->json(['success' => false, 'message' => 'Invalid data format received from the API.'], 422);
            }

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'An error occurred while fetching data from the API.', 'error' => $e->getMessage()], 500);
        }

        if (empty($employeesData)) {
            return response()->json(['success' => true, 'message' => 'No employees to synchronize.', 'data' => ['created' => 0, 'updated' => 0, 'failed' => 0]], 200);
        }

        // Pre-load and cache related data to minimize DB queries
        $companyNames       = array_unique(array_filter(array_map('strtolower', array_column($employeesData, 'company_name'))));
        $locationNames      = array_unique(array_filter(array_map('strtolower', array_column($employeesData, 'location'))));
        $divisionNames      = array_unique(array_filter(array_map('strtolower', array_column($employeesData, 'section'))));
        $designationNames   = array_unique(array_filter(array_map('strtolower', array_column($employeesData, 'designation'))));
        $shiftNames         = array_unique(array_filter(array_map('strtolower', array_column($employeesData, 'shift_name'))));
        $brandNames         = array_unique(array_filter(array_map('strtolower', array_column($employeesData, 'brand_name'))));
        $dmNames            = array_unique(array_filter(array_map('strtolower', array_column($employeesData, 'dm'))));

        $companyMap     = Company::whereIn(DB::raw('LOWER(company_name)'), $companyNames)
            ->pluck('id', 'company_name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $locationMap    = Location::whereIn(DB::raw('LOWER(location_name)'), $locationNames)
            ->pluck('id', 'location_name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $divisionMap    = Division::whereIn(DB::raw('LOWER(name)'), $divisionNames)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $designationMap = Designation::whereIn(DB::raw('LOWER(name)'), $designationNames)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $shiftMap       = Shift::whereIn(DB::raw('LOWER(shift_name)'), $shiftNames)
            ->pluck('id', 'shift_name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $brandMap       = Brand::whereIn(DB::raw('LOWER(brand_name)'), $brandNames)
            ->pluck('id', 'brand_name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $userMap        = User::whereIn(DB::raw('LOWER(name)'), $dmNames)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);

        $maps = [
            'company'     => $companyMap,
            'location'    => $locationMap,
            'division'    => $divisionMap,
            'designation' => $designationMap,
            'shift'       => $shiftMap,
            'brand'       => $brandMap,
            'user'        => $userMap,
        ];

        $updateColumns = [
            'employee_name',
            'mobile_number',
            'basic_salary',
            'status',
            'mode',
            'date_of_join',
            'date_of_leave',
            'weekly_off',
            'job_status',
            'ecode',
            'gender',
            'father_name',
            'spouse_name',
            'pf_number',
            'esi_number',
            'uan_number',
            'birthday_date',
            'age',
            'anniversary_date',
            'permanent_unique_id',
            'employee_photo',
            'is_active',
            'company_id',
            'location_id',
            'division_id',
            'designation_id',
            'shift_id',
            'brand_id',
            'reporting_dm',
            'is_delete',
            'deleted_by',
            'deleted_at',
            'updated_at',
        ];

        $results = [
            'created' => 0,
            'updated' => 0,
            'failed'  => 0,
            'errors'  => [],
        ];

        $chunkSize = 100;
        $chunks = array_chunk($employeesData, $chunkSize, true);

        foreach ($chunks as $chunk) {
            $batchRecords = [];
            $batchCodes   = [];

            // 1. Validate & prepare rows; isolate rows with invalid data
            foreach ($chunk as $index => $employeeData) {
                try {
                    $mapped = $this->prepareSyncRecord($employeeData, $maps);
                    $batchRecords[] = $mapped;
                    $batchCodes[]   = $mapped['employee_code'];
                } catch (\Throwable $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'index'         => $index,
                        'employee_code' => $employeeData['employee_code'] ?? 'N/A',
                        'error'         => $e->getMessage(),
                    ];
                }
            }

            if (empty($batchRecords)) {
                continue;
            }

            // 2. Perform bulk upsert in a single database query
            try {
                DB::beginTransaction();

                $existingCodes = Employee::withTrashed()
                    ->whereIn('employee_code', $batchCodes)
                    ->pluck('employee_code')
                    ->map(fn ($code) => mb_strtoupper($code))
                    ->all();

                $existingLookup = array_flip($existingCodes);
                $chunkUpdated = 0;
                $chunkCreated = 0;

                foreach ($batchCodes as $code) {
                    if (isset($existingLookup[$code])) {
                        $chunkUpdated++;
                    } else {
                        $chunkCreated++;
                    }
                }

                Employee::upsert($batchRecords, ['employee_code'], $updateColumns);

                DB::commit();

                $results['created'] += $chunkCreated;
                $results['updated'] += $chunkUpdated;
            } catch (\Throwable $chunkError) {
                DB::rollBack();

                // Fallback: If chunk upsert fails, isolate row-by-row
                foreach ($batchRecords as $record) {
                    try {
                        DB::beginTransaction();
                        $isExisting = Employee::withTrashed()->where('employee_code', $record['employee_code'])->exists();
                        Employee::upsert([$record], ['employee_code'], $updateColumns);
                        DB::commit();

                        if ($isExisting) {
                            $results['updated']++;
                        } else {
                            $results['created']++;
                        }
                    } catch (\Throwable $e) {
                        DB::rollBack();
                        $results['failed']++;
                        $results['errors'][] = [
                            'index'         => null,
                            'employee_code' => $record['employee_code'],
                            'error'         => $e->getMessage(),
                        ];
                    }
                }
            }
        }

        return response()->json(['success' => true, 'message' => 'Employees synchronized successfully.', 'data' => $results], 200);
    }

    /**
     * Validate and map an incoming employee record for bulk upserting.
     *
     * @return array
     * @throws \Exception
     */
    private function prepareSyncRecord(array $employeeData, array $maps): array
    {
        $record = array_map(function ($value) {
            return is_string($value) && trim($value) === '' ? null : $value;
        }, $employeeData);

        $validator = Validator::make($record, [
            'employee_code' => ['required', 'string', 'max:255'],
            'name'          => ['required', 'string', 'max:255'],
            'mobile_no'     => ['nullable', 'string', 'max:20'],
            'basic_salary'  => ['nullable', 'numeric', 'min:0'],
            'doj'           => ['nullable', 'date'],
            'dol'           => ['nullable', 'date', 'after_or_equal:doj'],
            'birthday'      => ['nullable', 'date'],
            'anniversary'   => ['nullable', 'date'],
            'age'           => ['nullable', 'numeric', 'min:0', 'max:150'],
            'gender'        => ['nullable', 'string', 'max:50'],
            'adhaar_no'     => ['nullable', 'string', 'max:20'],
            'pf_no'         => ['nullable', 'string', 'max:30'],
            'esi_no'        => ['nullable', 'string', 'max:30'],
            'uan_no'        => ['nullable', 'string', 'max:30'],
            'status'        => ['nullable', 'string', 'max:50'],
            'job_status'    => ['nullable', 'string', 'max:50'],
            'mode'          => ['nullable', 'string', 'max:50'],
            'weekly_off'    => ['nullable', 'string', 'max:50'],
            'ecode'         => ['nullable', 'string', 'max:50'],
        ], [
            'employee_code.required' => 'Employee code is required.',
            'name.required'          => 'Employee name is required.',
            'dol.after_or_equal'     => 'Date of leave must be on or after date of join.',
            'basic_salary.numeric'   => 'Basic salary must be a valid number.',
        ]);

        if ($validator->fails()) {
            throw new \Exception('Validation failed: ' . $validator->errors()->first());
        }

        $now = now();
        $code = mb_strtoupper(trim($record['employee_code']));

        $mappedData = [
            'employee_code'         => $code,
            'employee_name'         => mb_strtolower(trim($record['name'])),
            'mobile_number'         => $record['mobile_no'] ?? null,
            'basic_salary'          => isset($record['basic_salary']) && is_numeric($record['basic_salary']) ? (float) $record['basic_salary'] : null,
            'status'                => $record['status'] ?? null,
            'mode'                  => $record['mode'] ?? null,
            'date_of_join'          => $record['doj'] ?? null,
            'date_of_leave'         => $record['dol'] ?? null,
            'weekly_off'            => $record['weekly_off'] ?? null,
            'job_status'            => isset($record['job_status']) ? mb_strtolower(trim($record['job_status'])) : null,
            'ecode'                 => isset($record['ecode']) ? mb_strtoupper(trim($record['ecode'])) : null,
            'gender'                => isset($record['gender']) ? mb_strtolower(trim($record['gender'])) : null,
            'father_name'           => isset($record['father_name']) ? mb_strtolower(trim($record['father_name'])) : null,
            'spouse_name'           => isset($record['spouse_name']) ? mb_strtolower(trim($record['spouse_name'])) : null,
            'pf_number'             => isset($record['pf_no']) ? mb_strtoupper(trim($record['pf_no'])) : null,
            'esi_number'            => isset($record['esi_no']) ? mb_strtoupper(trim($record['esi_no'])) : null,
            'uan_number'            => isset($record['uan_no']) ? mb_strtoupper(trim($record['uan_no'])) : null,
            'birthday_date'         => $record['birthday'] ?? null,
            'age'                   => isset($record['age']) && is_numeric($record['age']) ? (float) $record['age'] : null,
            'anniversary_date'      => $record['anniversary'] ?? null,
            'permanent_unique_id'   => $record['adhaar_no'] ?? null,
            'employee_photo'        => $record['picture'] ?? null,
            'is_active'             => in_array(strtolower($record['status'] ?? ''), ['active', '1', 'true'], true),
            'company_id'            => $maps['company'][strtolower($record['company_name'] ?? '')] ?? null,
            'location_id'           => $maps['location'][strtolower($record['location'] ?? '')] ?? null,
            'division_id'           => $maps['division'][strtolower($record['section'] ?? '')] ?? null,
            'designation_id'        => $maps['designation'][strtolower($record['designation'] ?? '')] ?? null,
            'shift_id'              => $maps['shift'][strtolower($record['shift_name'] ?? '')] ?? null,
            'brand_id'              => $maps['brand'][strtolower($record['brand_name'] ?? '')] ?? null,
            'reporting_dm'          => $maps['user'][strtolower($record['dm'] ?? '')] ?? null,
            'is_delete'             => false,
            'deleted_by'            => null,
            'deleted_at'            => null,
            'created_at'            => $now,
            'updated_at'            => $now,
        ];

        $missingRelations = [];
        if (!empty($record['company_name']) && is_null($mappedData['company_id'])) $missingRelations[] = 'Company: ' . $record['company_name'];
        if (!empty($record['location']) && is_null($mappedData['location_id'])) $missingRelations[] = 'Location: ' . $record['location'];
        if (!empty($record['section']) && is_null($mappedData['division_id'])) $missingRelations[] = 'Division/Section: ' . $record['section'];
        if (!empty($record['designation']) && is_null($mappedData['designation_id'])) $missingRelations[] = 'Designation: ' . $record['designation'];
        if (!empty($record['shift_name']) && is_null($mappedData['shift_id'])) $missingRelations[] = 'Shift: ' . $record['shift_name'];
        if (!empty($record['brand_name']) && is_null($mappedData['brand_id'])) $missingRelations[] = 'Brand: ' . $record['brand_name'];
        if (!empty($record['dm']) && is_null($mappedData['reporting_dm'])) $missingRelations[] = 'Reporting DM: ' . $record['dm'];

        if (!empty($missingRelations)) {
            throw new \Exception('Missing related data: ' . implode(', ', $missingRelations));
        }

        return $mappedData;
    }

    /**
     * Generate a unique sequential employee code with optional Financial Year format (e.g. EMP24-001).
     *
     * @param string $prefix Prefix string (default: 'EMP')
     * @param int $padding Number of digits to pad (default: 3)
     * @param bool $withFinancialYear Append current FY 2-digit year (e.g. 24 for FY 2024-25)
     * @param string $separator Separator between FY/prefix and sequential number (default: '-')
     */
    public static function generateEmployeeCode(
        string $prefix = 'EMP',
        int $padding = 3,
        bool $withFinancialYear = true,
        string $separator = '-'
    ): string
    {
        if ($withFinancialYear) {
            $currentMonth = (int) date('n');
            $currentYear = (int) date('y');
            // April (month 4) onwards is current year's FY, Jan-Mar belongs to previous year's FY
            $financialYear = $currentMonth >= 4 ? $currentYear : ($currentYear - 1);
            $fullPrefix = $prefix . $financialYear . $separator;
        } else {
            $fullPrefix = $prefix . ($separator !== '' ? $separator : '');
        }

        $lastEmployee = Employee::withTrashed()
            ->where('employee_code', 'LIKE', $fullPrefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $nextNumber = 1;
        if ($lastEmployee && preg_match('/^' . preg_quote($fullPrefix, '/') . '(\d+)$/i', (string) $lastEmployee->employee_code, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        }

        do {
            $code = $fullPrefix . str_pad((string) $nextNumber, $padding, '0', STR_PAD_LEFT);
            $exists = Employee::withTrashed()->where('employee_code', $code)->exists();
            $nextNumber++;
        } while ($exists);

        return $code;
    }

    private function getValidationRules(?string $employeeType = null, $brandId = null): array
    {
        $normalizedType = str_replace('_', '-', strtolower(trim((string) $employeeType)));
        $isBrandType = in_array($normalizedType, ['brand-type-a', 'brand-type-b'], true);

        return [
            'employee_code' => ['nullable', 'string', 'max:255'],
            'employee_name' => 'required|string|max:255',
            'mobile_number' => [
                'required',
                'string',
                'regex:/^[6-9]\d{9}$/',
            ],
            'email_id' => [
                'required',
                'string',
                'max:255',
                'email:rfc,filter',
                'regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
            ],
            'telegram_username' => ['nullable', 'string', 'max:255'],
            'chat_id' => ['nullable', 'string', 'max:255'],
            'pan_number' => [
                'nullable',
                'string',
                'max:10',
                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/i',
            ],
            'aadhar_number' => ['required', 'string', 'digits:12'],
            'permanent_unique_id' => ['sometimes', 'string', 'max:255'],
            'company_id' => ['required', Rule::exists('companies', 'id')->where(fn ($query) => $query->where('is_delete', false)->where('is_active', true))],
            'location_id' => ['required', Rule::exists('locations', 'id')->where(fn ($query) => $query->where('is_delete', false)->where('is_active', true))],
            'division_id' => ['required', Rule::exists('divisions', 'id')->where(fn ($query) => $query->where('is_delete', false)->where('is_active', true))],
            'designation_id' => ['required', Rule::exists('designations', 'id')->where(fn ($query) => $query->where('is_delete', false)->where('is_active', true))],
            'shift_id' => ['required', Rule::exists('shifts', 'id')->where(fn ($query) => $query->where('is_delete', false)->where('is_active', true))],
            'brand_id' => [
                $isBrandType ? 'required' : 'nullable',
                Rule::exists('brands', 'id')->where(fn ($query) => $query->where('is_delete', false)->where('is_active', true)),
            ],
            'vendor_id' => [
                $isBrandType ? 'required' : 'nullable',
                Rule::exists('vendors', 'id')->where(fn ($query) => $query->where('is_delete', false)->where('is_active', true)),
                function ($attribute, $value, $fail) use ($brandId) {
                    if ($value && $brandId) {
                        $isLinked = DB::table('vendor_brands')
                            ->where('brand_id', $brandId)
                            ->where('vendor_id', $value)
                            ->where('is_delete', false)
                            ->where('is_active', true)
                            ->exists();
                        if (!$isLinked) {
                            $fail('The selected vendor is not associated with the selected brand.');
                        }
                    }
                },
            ],
            'reporting_dm' => 'nullable|exists:users,id',
            'reporting_sm' => 'nullable|exists:users,id',
            'blood_group' => 'nullable|string|max:255',
            'gender' => 'nullable|string|max:255',
            'age' => 'nullable|integer|min:0',
            'job_status' => 'nullable|string|max:255',
            'ecode' => 'nullable|string|max:255',
            'birthday_date' => 'nullable|date',
            'status' => 'nullable|string|max:255',
            'mode' => 'nullable|string|max:255',
            'father_name' => 'nullable|string|max:255',
            'current_address' => 'nullable|string',
            'permanent_address' => 'nullable|string',
            'marital_status' => 'nullable|string|max:255',
            'spouse_name' => 'nullable|string|max:255',
            'anniversary_date' => 'nullable|date',
            'basic_salary' => 'nullable|numeric|min:0',
            'date_of_join' => 'nullable|date',
            'date_of_leave' => 'nullable|date|after_or_equal:date_of_join',
            'pf_number' => 'nullable|string|max:22',
            'uan_number' => [
                'nullable',
                'string',
                'max:12',
                'regex:/^\d{12}$/',
            ],
            'esi_number' => [
                'nullable',
                'string',
                'max:17',
                'regex:/^\d{17}$/',
            ],
            'bank_name' => 'nullable|string|max:255',
            'account_number' => [
                'nullable',
                'string',
                'regex:/^\d{9,20}$/',
            ],
            'ifsc_number' => [
                'nullable',
                'string',
                'max:11',
                'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/i',
            ],
            'weekly_off' => 'nullable|string|max:255',           
            'employee_type' => 'nullable|string|max:255',
            'employee_photo' => 'nullable|string',
            'emergency_contact_person_name' => 'nullable|string|max:255',
            'emergency_contact_person_relation' => 'nullable|string|max:255',
            'emergency_contact_person_phone_number' => [
                'nullable',
                'string',
                'regex:/^[6-9]\d{9}$/',
            ],
            'is_active' => 'sometimes|boolean',
            'is_delete' => 'sometimes|boolean',
            'deleted_by' => 'nullable|integer',
            'deleted_at' => 'nullable|date',
        ];
    }

    /**
     * Get user-friendly validation error messages.
     */
    private function getValidationMessages(): array
    {
        return [
            'employee_name.required' => 'Employee name is required.',
            'employee_code.unique' => 'This employee code is already in use.',
            'mobile_number.regex' => 'Please enter a valid 10-digit mobile number.',
            'mobile_number.unique' => 'This mobile number is already registered.',
            'email_id.email' => 'Please enter a valid email address.',
            'email_id.regex' => 'Please enter a valid email address format (e.g. user@example.com).',
            'email_id.unique' => 'This email address is already registered.',
            'pan_number.regex' => 'Please enter a valid PAN number format (e.g. ABCDE1234F).',
            'pan_number.unique' => 'This PAN number is already registered.',
            'pan_number.max' => 'PAN number cannot exceed 10 characters.',
            'aadhar_number.digits' => 'Aadhaar number must be exactly 12 digits.',
            'aadhar_number.required' => 'Aadhaar number is required.',
            'aadhar_number.unique' => 'This Aadhaar number is already registered.',
           // 'permanent_unique_id.required' => 'Permanent Unique ID is required.',
            'permanent_unique_id.unique' => 'This Permanent Unique ID is already registered.',
            'company_id.exists' => 'The selected company is invalid or inactive.',
            'company_id.required' => 'Company is required.',
            'location_id.required' => 'Location is required.',
            'division_id.required' => 'Division/Section is required.',
            'designation_id.required' => 'Designation is required.',
            'shift_id.required' => 'Shift is required.',
            'location_id.exists' => 'The selected location is invalid or inactive.',
            'division_id.exists' => 'The selected division is invalid or inactive.',
            'designation_id.exists' => 'The selected designation is invalid or inactive.',
            'shift_id.exists' => 'The selected shift is invalid or inactive.',
            'brand_id.required' => 'Brand is required when employee type is Brand-Type-A or Brand-Type-B.',
            'brand_id.exists' => 'The selected brand is invalid or inactive.',
            'vendor_id.required' => 'Vendor is required when employee type is Brand-Type-A or Brand-Type-B.',
            'vendor_id.exists' => 'The selected vendor is invalid or inactive.',
            'reporting_dm.exists' => 'The selected Reporting DM is invalid.',
            'reporting_sm.exists' => 'The selected Reporting SM is invalid.',
            'date_of_leave.after_or_equal' => 'Date of leaving must be on or after the date of joining.',
            'basic_salary.numeric' => 'Basic salary must be a valid numeric amount.',
            'basic_salary.min' => 'Basic salary cannot be negative.',
            'pf_number.max' => 'PF number cannot exceed 22 characters.',
            'uan_number.regex' => 'Please enter a valid 12-digit UAN number.',
            'uan_number.max' => 'UAN number cannot exceed 12 digits.',
            'esi_number.regex' => 'Please enter a valid 17-digit ESI number.',
            'esi_number.max' => 'ESI number cannot exceed 17 digits.',
            'account_number.regex' => 'Please enter a valid bank account number (9 to 20 digits).',
            'account_number.max' => 'Bank account number cannot exceed 20 digits.',
            'ifsc_number.regex' => 'Please enter a valid 11-character IFSC code (e.g. SBIN0001234).',
            'ifsc_number.max' => 'IFSC code cannot exceed 11 characters.',
            'age.integer' => 'Age must be a valid whole number.',
            'age.min' => 'Age cannot be negative.',
            'emergency_contact_person_phone_number.regex' => 'Please enter a valid 10-digit emergency contact phone number.',
        ];
    }

    /**
     * Restore a soft-deleted employee when none of its unique values are in use.
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
                'message' => 'Unauthorized. Only Super Admin or Admin can restore employees.',
            ], 403);
        }

        $employee = Employee::withTrashed()->where('is_delete', true)->find($id);

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted employee not found.',
            ], 404);
        }

        $conflicts = [];

        foreach (self::UNIQUE_FIELDS as $column => $label) {
            $value = $employee->{$column};
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $duplicate = Employee::withTrashed()
                ->where('is_delete', false)
                ->where('id', '!=', $employee->id)
                ->whereRaw("LOWER({$column}) = ?", [mb_strtolower(trim($value))])
                ->exists();

            if ($duplicate) {
                $conflicts[] = $label;
            }
        }

        if ($conflicts !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot restore. An employee with the same ' . implode(', ', $conflicts) . ' already exists.',
            ], 409);
        }

        $employee->restore();
        $employee->update([
            'is_delete' => false,
            'deleted_by' => null,
            'deleted_at' => null,
            'is_active' => true,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee restored successfully.',
            'data' => $employee->fresh(),
        ], 200);
    }

    public static function validateEmployeeUniqueValues(
        ValidatorContract $validator,
        array $values,
        ?int $ignoreId = null
    ): void {
        $validator->after(function ($validator) use ($values, $ignoreId): void {
            foreach (self::UNIQUE_FIELDS as $column => $label) {
                $value = $values[$column] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }

                $existing = Employee::withTrashed()
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

    private function validationErrorResponse(ValidatorContract $validator)
    {
        $errors = $validator->errors()->toArray();
        $duplicateMessages = collect($errors)->flatten();
        $isDuplicate = $duplicateMessages->contains(function ($message) {
            $msg = strtolower($message);
            return str_contains($msg, 'already been taken')
                || str_contains($msg, 'already in use')
                || str_contains($msg, 'already registered')
                || str_contains($msg, 'already exists');
        });

        $message = 'Validation failed.';
        if ($isDuplicate) {
            if (isset($errors['employee_code'])) {
                $message = 'Employee code already exists.';
            } elseif (isset($errors['email_id'])) {
                $message = 'Email address already registered.';
            } elseif (isset($errors['mobile_number'])) {
                $message = 'Mobile number already registered.';
            } elseif (isset($errors['aadhar_number'])) {
                $message = 'Aadhaar number already registered.';
            } elseif (isset($errors['pan_number'])) {
                $message = 'PAN number already registered.';
            } else {
                $message = 'Employee record already exists.';
            }
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $isDuplicate ? 409 : 422);
    }

    private function isDuplicateException(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }
}