<?php

namespace App\Exports;

use App\Models\EmployeeAttendance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeAttendanceExport
{
    /**
     * Download attendance records as a streamed CSV file.
     */
    public function download(Request $request): StreamedResponse
    {
        $employeeId = $request->query('employee_id');
        $startDate  = $request->query('start_date');
        $endDate    = $request->query('end_date');
        $search     = mb_strtolower(trim((string) $request->query('search', '')));

        // Fetch data with filters
        $attendances = EmployeeAttendance::with([
            'employee:id,employee_name,employee_code',
            'details' => function ($query): void {
                $query->orderBy('in_out_time', 'asc');
            },
        ])
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($startDate, fn ($q) => $q->whereDate('attendance_date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('attendance_date', '<=', $endDate))
            ->when($search !== '', function ($q) use ($search): void {
                $q->whereHas('employee', function ($empQuery) use ($search): void {
                    $empQuery->whereRaw('LOWER(employee_name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(employee_code) LIKE ?', ["%{$search}%"]);
                });
            })
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->get();

        // Calculate maximum punches
        $maxPunches = $attendances->max(fn ($attendance) => $attendance->details->count()) ?? 0;
        if ($maxPunches < 2) {
            $maxPunches = 2;
        }

        // Build Headers
        $headers = [
            'Date',
            'Employee Code',
            'Employee Name',
            'Total Working Hours',
        ];

        for ($i = 1; $i <= $maxPunches; $i++) {
            $headers[] = 'Punch-' . $i;
        }

        $filename = 'employee_attendances_' . date('Y_m_d_His') . '.csv';

        // Return Streamed Response natively (No Maatwebsite package required)
        return response()->streamDownload(function () use ($headers, $attendances, $maxPunches): void {
            $output = fopen('php://output', 'wb');
            
            // Add BOM to fix UTF-8 in Excel
            fputs($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);

            foreach ($attendances as $attendance) {
                $row = [
                    Carbon::parse($attendance->attendance_date)->format('Y-m-d'),
                    $attendance->employee->employee_code ?? 'N/A',
                    $attendance->employee->employee_name ?? 'N/A',
                    $attendance->total_working_hours ?? '0.00',
                ];

                $details = $attendance->details;
                for ($i = 0; $i < $maxPunches; $i++) {
                    $row[] = isset($details[$i])
                        ? Carbon::parse($details[$i]->in_out_time)->format('H:i:s') // Time in 24hr format
                        : '';
                }

                fputcsv($output, $row);
            }

            fclose($output);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}