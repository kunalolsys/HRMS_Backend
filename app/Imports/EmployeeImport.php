<?php

namespace App\Imports;

use App\Http\Controllers\Api\EmployeeController;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Designation;
use App\Models\Division;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
 
class EmployeeImport
{
    protected array $errors = [];
    protected array $lookupCaches = [];

    private const HEADERS = [
        'employee_name', 'mobile_number', 'email_id', 'pan_number',
        'aadhar_number', 'company_name', 'location_name', 'division_name', 'designation_name',
        'shift_name', 'brand_name', 'vendor_name', 'blood_group', 'gender', 'age', 'job_status',
        'ecode', 'birthday_date', 'status', 'mode', 'father_name', 'current_address',
        'permanent_address', 'marital_status', 'spouse_name', 'anniversary_date',
        'basic_salary', 'date_of_join', 'date_of_leave', 'pf_number', 'uan_number',
        'esi_number', 'bank_name', 'account_number', 'ifsc_number', 'weekly_off',
        'employee_type', 'emergency_contact_person_name',
        'emergency_contact_person_relation', 'emergency_contact_person_phone_number',
        'reporting_dm', 'reporting_sm', 'is_active'
    ];

    private const HEADERS_WITH_CODE = [
        'employee_code', 'employee_name', 'mobile_number', 'email_id', 'pan_number',
        'aadhar_number', 'company_name', 'location_name', 'division_name', 'designation_name',
        'shift_name', 'brand_name', 'vendor_name', 'blood_group', 'gender', 'age', 'job_status',
        'ecode', 'birthday_date', 'status', 'mode', 'father_name', 'current_address',
        'permanent_address', 'marital_status', 'spouse_name', 'anniversary_date',
        'basic_salary', 'date_of_join', 'date_of_leave', 'pf_number', 'uan_number',
        'esi_number', 'bank_name', 'account_number', 'ifsc_number', 'weekly_off',
        'employee_type', 'emergency_contact_person_name',
        'emergency_contact_person_relation', 'emergency_contact_person_phone_number',
        'reporting_dm', 'reporting_sm', 'is_active'
    ];

    private const HEADER_ALIASES = [
        'company'          => 'company_name',
        'company_id'       => 'company_name',
        'location'         => 'location_name',
        'location_id'      => 'location_name',
        'division'         => 'division_name',
        'division_id'      => 'division_name',
        'designation'      => 'designation_name',
        'designation_id'   => 'designation_name',
        'shift'            => 'shift_name',
        'shift_id'         => 'shift_name',
        'brand'            => 'brand_name',
        'brand_id'         => 'brand_name',
        'vendor'           => 'vendor_name',
        'vendor_id'        => 'vendor_name',
        'reporting_dm_id'  => 'reporting_dm',
        'reporting_sm_id'  => 'reporting_sm',        
        'aadhar_number'    => 'aadhar_number',
        
    ];

    public function import(UploadedFile $file): int
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read the import file.');
        }

        try {
            $headers = fgetcsv($handle);
            $headers = $headers === false ? [] : $this->normalizeHeaders($headers);
            $hasCodeHeader = ($headers === self::HEADERS_WITH_CODE);
            if ($headers !== self::HEADERS && !$hasCodeHeader) {
                $this->errors[] = array_merge(['row' => 1], array_fill_keys(self::HEADERS, ''), [
                    'error' => 'The CSV header does not match the employee import structure.',
                ]);
                return 0;
            }

            $imported = 0;
            $rowNumber = 1;
            $importedCodes = [];
            $importedAadhars = [];
            $activeHeaders = $hasCodeHeader ? self::HEADERS_WITH_CODE : self::HEADERS;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $data = [];
                foreach ($activeHeaders as $index => $header) {
                    $data[$header] = trim((string) ($row[$index] ?? ''));
                }

                $data['is_active'] = strtolower($data['is_active'] ?? '');
                $data['brand_staff'] = strtolower($data['brand_staff'] ?? '');

                $code = mb_strtoupper(trim((string) ($data['employee_code'] ?? '')));
                if ($code !== '' && isset($importedCodes[$code])) {
                    $this->addError($rowNumber, $data, ["The employee code '{$code}' is duplicated in this file."]);
                    continue;
                }

                // Aadhaar number sanitize karein (spaces/hyphens remove karein)
                $rawAadhar = $data['aadhar_number'] ?? '';
                $cleanAadhar = preg_replace('/\D/', '', (string) $rawAadhar);
                $data['aadhar_number'] = $cleanAadhar !== '' ? $cleanAadhar : null;

                // Check for duplicate Aadhaar inside the same CSV file
                if (!empty($data['aadhar_number'])) {
                    if (isset($importedAadhars[$data['aadhar_number']])) {
                        $this->addError($rowNumber, $data, ["The Aadhaar number '{$data['aadhar_number']}' is duplicated in this file."]);
                        continue;
                    }
                }

                if (!empty($data['employee_code']) && !empty($cleanAadhar)) {
                    $last4Aadhar = strlen($cleanAadhar) >= 4 ? substr($cleanAadhar, -4) : substr(trim((string) $rawAadhar), -4);
                    $data['permanent_unique_id'] = $code .'-'. $last4Aadhar;
                } else {
                    $data['permanent_unique_id'] = null;
                }

                // Resolve names/codes to database IDs
                $lookupErrors = [];
                $this->resolveForeignLookups($data, $lookupErrors);
                if (!empty($lookupErrors)) {
                    $this->addError($rowNumber, $data, $lookupErrors);
                    continue;
                }

                $validator = Validator::make($data, $this->validationRules($data), [
                    'brand_id.required' => 'Brand is required when employee type is Brand-Type-A or Brand-Type-B.',
                    'brand_id.exists' => 'The selected brand is invalid or inactive.',
                    'vendor_id.required' => 'Vendor is required when employee type is Brand-Type-A or Brand-Type-B.',
                    'vendor_id.exists' => 'The selected vendor is invalid or inactive.',
                    'mobile_number.regex' => 'Please enter a valid 10-digit mobile number.',
                    'email_id.email' => 'Please enter a valid email address.',
                    'email_id.regex' => 'Please enter a valid email address format (e.g. user@example.com).',
                    'pan_number.regex' => 'Please enter a valid PAN number format (e.g. ABCDE1234F).',
                    'pan_number.max' => 'PAN number cannot exceed 10 characters.',
                    'uan_number.regex' => 'Please enter a valid 12-digit UAN number.',
                    'esi_number.regex' => 'Please enter a valid 17-digit ESI number.',
                    'account_number.regex' => 'Please enter a valid bank account number (9 to 20 digits).',
                    'ifsc_number.regex' => 'Please enter a valid 11-character IFSC code (e.g. SBIN0001234).',
                    'emergency_contact_person_phone_number.regex' => 'Please enter a valid 10-digit emergency contact phone number.',
                ]);
                EmployeeController::validateEmployeeUniqueValues($validator, $data);

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                try {
                    DB::transaction(function () use (&$data, &$code, $cleanAadhar, &$importedCodes): void {
                        if (empty($data['employee_code'])) {
                            do {
                                $generatedCode = EmployeeController::generateEmployeeCode();
                            } while (isset($importedCodes[$generatedCode]));
                            $data['employee_code'] = $generatedCode;
                            $code = mb_strtoupper(trim($generatedCode));

                            if (!empty($cleanAadhar)) {
                                $last4Aadhar = strlen($cleanAadhar) >= 4 ? substr($cleanAadhar, -4) : substr(trim((string) $rawAadhar), -4);
                                $data['permanent_unique_id'] = $code .'-'. $last4Aadhar;
                            }
                        }

                        $values = array_map(fn ($value) => $value === '' ? null : $value, $data);
                        $values['is_active'] = $this->parseBooleanValue((string) ($data['is_active'] ?? ''));
                        $values['brand_staff'] = $this->parseBooleanValue((string) ($data['brand_staff'] ?? ''));
                        $values['created_by'] = Auth::id();

                        // Unset CSV name fields so they do not conflict with employee table columns
                        unset(
                            $values['company_name'],
                            $values['location_name'],
                            $values['division_name'],
                            $values['designation_name'],
                            $values['shift_name'],
                            $values['brand_name'],
                            $values['vendor_name']
                        );

                        Employee::create($values);
                    }, 5);
                    if ($code !== '') {
                        $importedCodes[$code] = true;
                    }
                    if (!empty($data['aadhar_number'])) {
                        $importedAadhars[$data['aadhar_number']] = true;
                    }
                    $imported++;
                } catch (\Throwable $exception) {
                    $this->addError($rowNumber, $data, [$this->friendlyError($exception)]);
                }
            }

            return $imported;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Resolve relation fields (by Name, Code, or ID) to their respective database IDs.
     */
    private function resolveForeignLookups(array &$data, array &$errors): void
    {
        $lookupDefinitions = [
            'company_name'     => ['company_id', Company::class, ['company_name', 'company_code'], 'Company'],
            'location_name'    => ['location_id', Location::class, ['location_name', 'location_code'], 'Location'],
            'division_name'    => ['division_id', Division::class, ['name'], 'Division'],
            'designation_name' => ['designation_id', Designation::class, ['name', 'code'], 'Designation'],
            'shift_name'       => ['shift_id', Shift::class, ['shift_name', 'shift_code'], 'Shift'],
            'brand_name'       => ['brand_id', Brand::class, ['brand_name', 'brand_code'], 'Brand'],
            'vendor_name'      => ['vendor_id', Vendor::class, ['vendor_name', 'vendor_code'], 'Vendor'],
           // 'reporting_dm'     => ['reporting_dm', User::class, ['name', 'email'], 'Reporting DM'],
           // 'reporting_sm'     => ['reporting_sm', User::class, ['name', 'email'], 'Reporting SM'],
        ];

        foreach ($lookupDefinitions as $sourceField => [$targetField, $modelClass, $columns, $label]) {
            $rawVal = trim((string) ($data[$sourceField] ?? ''));
            if ($rawVal === '') {
                $data[$targetField] = null;
                continue;
            }

            $cacheKey = $modelClass . ':' . mb_strtolower($rawVal);
            if (!array_key_exists($cacheKey, $this->lookupCaches)) {
                $lower = mb_strtolower($rawVal);
                $query = $modelClass::query();

                try {
                    $record = (clone $query)->where('is_delete', false)->where(function ($q) use ($columns, $lower, $rawVal) {
                        foreach ($columns as $column) {
                            $q->orWhereRaw("LOWER({$column}) = ?", [$lower]);
                        }
                        if (ctype_digit($rawVal)) {
                            $q->orWhere('id', (int) $rawVal);
                        }
                    })->first();
                } catch (\Throwable $e) {
                    $record = (clone $query)->where(function ($q) use ($columns, $lower, $rawVal) {
                        foreach ($columns as $column) {
                            $q->orWhereRaw("LOWER({$column}) = ?", [$lower]);
                        }
                        if (ctype_digit($rawVal)) {
                            $q->orWhere('id', (int) $rawVal);
                        }
                    })->first();
                }
                $this->lookupCaches[$cacheKey] = $record ? ['id' => $record->id, 'is_active' => (bool) ($record->is_active ?? true)] : null;
            }

            if ($this->lookupCaches[$cacheKey] === null) {
                $errors[] = "{$label} '{$rawVal}' was not found (by name, code, or ID).";
            } elseif (in_array($modelClass, [Company::class, Location::class, Division::class, Designation::class, Shift::class, Brand::class, Vendor::class], true) && !$this->lookupCaches[$cacheKey]['is_active']) {
                $errors[] = "{$label} '{$rawVal}' is inactive and cannot be assigned.";
            } else {
                $data[$targetField] = $this->lookupCaches[$cacheKey]['id'];
            }
        }
    }

    private function validationRules(array $data = []): array
    {
        $normalizedType = str_replace('_', '-', strtolower(trim((string) ($data['employee_type'] ?? ''))));
        $isBrandType = in_array($normalizedType, ['brand-type-a', 'brand-type-b'], true);
        $brandId = $data['brand_id'] ?? null;

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
            'pan_number' => [
                'nullable',
                'string',
                'max:10',
                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/i',
            ],
            'aadhar_number' => ['required', 'string', 'digits:12'],
            'permanent_unique_id' => ['nullable', 'string', 'max:255'],
            'company_id' => ['required', Rule::exists('companies', 'id')->where(fn ($query) => $query->where('is_delete', false)->where('is_active', true))],
            'telegram_username' => ['nullable', 'string', 'max:255'],
            'chat_id' => ['nullable', 'string', 'max:255'],
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
                            ->exists();
                        if (!$isLinked) {
                            $fail('The selected vendor is not associated with the selected brand.');
                        }
                    }
                },
            ],
            'reporting_dm' => 'nullable|string|max:255',
            'reporting_sm' => 'nullable|string|max:255',
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
            'uan_number' => ['nullable', 'string', 'max:12', 'regex:/^\d{12}$/'],
            'esi_number' => ['nullable', 'string', 'max:17', 'regex:/^\d{17}$/'],
            'bank_name' => 'nullable|string|max:255',
            'account_number' => ['nullable', 'string', 'regex:/^\d{9,20}$/'],
            'ifsc_number' => ['nullable', 'string', 'max:11', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/i'],
            'weekly_off' => 'nullable|string|max:255',
            // 'brand_staff' => 'nullable|string|in:true,false,yes,no,1,0',
            'employee_type' => 'nullable|string|max:255',
            'emergency_contact_person_name' => 'nullable|string|max:255',
            'emergency_contact_person_relation' => 'nullable|string|max:255',
            'emergency_contact_person_phone_number' => [
                'nullable',
                'string',
                'regex:/^[6-9]\d{9}$/',
            ],
            'is_active' => 'nullable|string|in:true,false,yes,no,active,inactive,1,0',
        ];
    }

    public function hasErrors(): bool { return !empty($this->errors); }

    public function downloadTemplate() {
        return response()->streamDownload(function () {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);
            fputcsv($output, [
                'John Doe', '9876543210', 'john@example.com', 'ABCDE1234F',
                '123456789012', 'Suvidha Ltd', 'Delhi Store', 'Retail', 'Sales Executive',
                'Morning Shift', 'Nike', 'Acme Vendor', 'O+', 'Male', '28', 'Confirmed',
                'EC001', '1996-05-15', 'Active', 'On-roll', 'Father Name', 'Current Address',
                'Permanent Address', 'Single', '', '',
                '25000', '2024-01-01', '', 'PF123456', '100000000001',
                '11000000000000001', 'HDFC Bank', '123456789012', 'HDFC0001234', 'Sunday',
                'false', 'Staff', 'Jane Doe', 'Mother', '9876543211',
                'DM Manager Name', 'SM Manager Name', 'true',
            ]);
            fclose($output);
        }, 'employee-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function storeErrors(): string {
        $output = fopen('php://memory', 'r+');
        fputcsv($output, array_merge(['row'], self::HEADERS, ['error']));
        foreach ($this->errors as $error) { fputcsv($output, $error); }
        rewind($output);
        $contents = stream_get_contents($output);
        fclose($output);
        $filename = 'employee-import-errors/' . uniqid('errors-', true) . '.csv';
        Storage::disk('local')->put($filename, $contents);
        return $filename;
    }

    public function errors(): array { return $this->errors; }

    private function normalizeHeaders(array $headers): array
    {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        return array_map(function ($header) {
            $cleaned = strtolower(trim((string) $header));
            return self::HEADER_ALIASES[$cleaned] ?? $cleaned;
        }, $headers);
    }

    private function addError(int $row, array $data, array $messages): void
    {
        $rowOutput = ['row' => $row];
        foreach (self::HEADERS as $header) {
            $rowOutput[$header] = $data[$header] ?? '';
        }
        $rowOutput['error'] = implode(' ', $messages);
        $this->errors[] = $rowOutput;
    }

    private function parseBooleanValue(string $value): bool
    {
        if (trim($value) === '') {
            return true; // Default to true if empty
        }
        return in_array(strtolower(trim($value)), ['true', 'yes', 'active', '1'], true);
    }
    private function friendlyError(\Throwable $exception): string { return $exception instanceof \Illuminate\Database\QueryException && $exception->getCode() === '23000' ? 'A unique employee value already exists.' : 'The row could not be imported: ' . $exception->getMessage(); }
}