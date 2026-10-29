<?php

namespace App\Exports;

use App\Models\Employee;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeExport
{
    use FiltersTrashedExport;

    private const HEADERS = [
        'employee_code', 'employee_name', 'mobile_number', 'email_id', 'telegram_username',
        'chat_id', 'pan_number',
        'permanent_unique_id', 'company_id', 'location_id', 'division_id', 'designation_id',
        'shift_id', 'brand_id', 'vendor_id', 'blood_group', 'gender', 'age', 'job_status',
        'ecode', 'birthday_date', 'status', 'mode', 'father_name', 'current_address',
        'permanent_address', 'marital_status', 'spouse_name', 'anniversary_date',
        'basic_salary', 'date_of_join', 'date_of_leave', 'pf_number', 'uan_number',
        'esi_number', 'bank_name', 'account_number', 'ifsc_number', 'weekly_off',
        'employee_type', 'emergency_contact_person_name','aadhar_number',
        'emergency_contact_person_relation', 'emergency_contact_person_phone_number',
        'reporting_dm', 'reporting_sm', 'is_active'
    ];

    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $showTrashed = $this->shouldExportTrashed($request);

        return response()->streamDownload(function () use ($search, $status, $showTrashed): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);

            Employee::withTrashed()
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->whereRaw('LOWER(employee_code) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(employee_name) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(email_id) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(mobile_number) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->chunkById(200, function ($employees) use ($output): void {
                    foreach ($employees as $employee) {
                        $data = $employee->only(self::HEADERS);
                        $data['is_active'] = $employee->is_active ? 'true' : 'false';
                        $data['brand_staff'] = $employee->brand_staff ? 'true' : 'false';
                        fputcsv($output, $data);
                    }
                });

            fclose($output);
        }, 'employees.csv', ['Content-Type' => 'text/csv']);
    }
}