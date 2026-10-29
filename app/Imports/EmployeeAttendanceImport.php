<?php

namespace App\Imports;

use App\Http\Traits\HandlesImport;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceDetail;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeAttendanceImport
{
    use HandlesImport;

    /**
     * CSV ke liye mandatory base headers.
     */
    private const BASE_HEADERS = ['date', 'employee_code'];

    /**
     * Validation errors list.
     *
     * @var array<int, array{row: int, errors: array<string>, data: array<string, mixed>}>
     */
    protected array $errors = [];

    /**
     * Rejected rows raw data (for error file generation).
     *
     * @var array<int, array>
     */
    protected array $rawFailedRows = [];

    /**
     * Original CSV headers.
     *
     * @var array<int, string>
     */
    protected array $fileHeaders = [];

    /**
     * Main import function to process the uploaded CSV file.
     */
    public function import(UploadedFile $file): int
    {
        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to read the import file.');
        }

        try {
            $rawHeaders = fgetcsv($handle);
            $headers = $rawHeaders === false ? [] : $this->normalizeHeaders($rawHeaders);
            $this->fileHeaders = $headers;
            $imported = 0;

            // 1. Mandatory Base Headers Validation
            foreach (self::BASE_HEADERS as $requiredHeader) {
                if (!in_array($requiredHeader, $headers, true)) {
                    $this->addError(1, [], ['The CSV must contain "date" and "employee_code" columns.']);
                    return 0;
                }
            }

            // 2. Identify & Index Dynamic Punch Columns (e.g., punch_1, Punch-2, etc.)
            $punchColumns = [];
            foreach ($headers as $index => $header) {
                if (preg_match('/^punch[-_]?(\d+)$/i', $header, $matches)) {
                    $number = (int) $matches[1];
                    $punchColumns[$number] = [
                        'index'  => $index,
                        'header' => $header,
                    ];
                }
            }

            // 3. Header-Level Pair Validation (Punch-1 ke sath Punch-2 hona zaroori hai)
            $headerErrors = [];
            if (empty($punchColumns)) {
                $headerErrors[] = 'The CSV must contain at least "punch_1" and "punch_2" columns.';
            } else {
                foreach ($punchColumns as $num => $info) {
                    if ($num % 2 !== 0) {
                        $expectedEven = $num + 1;
                        if (!isset($punchColumns[$expectedEven])) {
                            $headerErrors[] = "Header '{$info['header']}' requires a matching pair column 'punch_{$expectedEven}'.";
                        }
                    } else {
                        $expectedOdd = $num - 1;
                        if (!isset($punchColumns[$expectedOdd])) {
                            $headerErrors[] = "Header '{$info['header']}' requires a matching pair column 'punch_{$expectedOdd}'.";
                        }
                    }
                }
            }

            if (!empty($headerErrors)) {
                $this->addError(1, [], $headerErrors);
                return 0;
            }

            ksort($punchColumns);

            $rowNumber = 1;
            $importedRecords = [];
            $employeeCache = [];
            $userId = Auth::id();
            $maxPunchNumber = !empty($punchColumns) ? max(array_keys($punchColumns)) : 0;

            // 4. Process Rows
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                $data = [];
                foreach ($headers as $index => $header) {
                    $data[$header] = trim((string) ($row[$index] ?? ''));
                }

                $dateVal = $data['date'] ?? '';
                $empCode = $data['employee_code'] ?? '';
                $uniqueKey = strtolower($empCode) . '_' . $dateVal;

                // Basic validation for date and employee code
                $validator = Validator::make([
                    'date'          => $dateVal,
                    'employee_code' => $empCode,
                ], [
                    'date'          => ['required', 'date'],
                    'employee_code' => ['required', 'string', 'max:255'],
                ], [
                    'date.required'          => 'Attendance date is required.',
                    'date.date'              => 'Attendance date format is invalid.',
                    'employee_code.required' => 'Employee code is required.',
                ]);

                // Check for duplicates inside the same file
                if (isset($importedRecords[$uniqueKey])) {
                    $validator->after(function ($validator): void {
                        $validator->errors()->add('employee_code', 'Duplicate entry for this employee and date exists in this file.');
                    });
                }

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                // 5. In-Memory Employee Cache Lookup
                if (!array_key_exists($empCode, $employeeCache)) {
                    $employeeCache[$empCode] = Employee::with('shift')->where('employee_code', $empCode)->first();
                }

                $employee = $employeeCache[$empCode];
                if (!$employee) {
                    $this->addError($rowNumber, $data, ["Employee with code '{$empCode}' does not exist."]);
                    continue;
                }

                $formattedDate = date('Y-m-d', strtotime($dateVal));
                $punchErrors = [];
                $punchTimestamps = [];
                $punchDateTimes = [];
                $lastPunchTs = null;

                // 6. Row-Level Punch Pair & Time Validation
                for ($i = 1; $i <= $maxPunchNumber; $i += 2) {
                    if (!isset($punchColumns[$i], $punchColumns[$i + 1])) {
                        continue;
                    }

                    $inHeader = $punchColumns[$i]['header'];
                    $outHeader = $punchColumns[$i + 1]['header'];

                    $valIn = trim((string) ($row[$punchColumns[$i]['index']] ?? ''));
                    $valOut = trim((string) ($row[$punchColumns[$i + 1]['index']] ?? ''));

                    // Check missing pairs
                    if ($valIn !== '' && $valOut === '') {
                        $punchErrors[] = "{$outHeader} is required when {$inHeader} is provided.";
                    } elseif ($valIn === '' && $valOut !== '') {
                        $punchErrors[] = "{$outHeader} cannot be filled without {$inHeader}.";
                    } elseif ($valIn !== '' && $valOut !== '') {
                        $timeInStr = preg_match('/^\d{4}-\d{2}-\d{2}/', $valIn) ? $valIn : "{$formattedDate} {$valIn}";
                        $timeOutStr = preg_match('/^\d{4}-\d{2}-\d{2}/', $valOut) ? $valOut : "{$formattedDate} {$valOut}";

                        $tsIn = strtotime($timeInStr);
                        $tsOut = strtotime($timeOutStr);

                        if ($tsIn === false) {
                            $punchErrors[] = "Invalid time format in {$inHeader}: '{$valIn}'.";
                        }
                        if ($tsOut === false) {
                            $punchErrors[] = "Invalid time format in {$outHeader}: '{$valOut}'.";
                        }

                        if ($tsIn !== false && $tsOut !== false) {
                            // If previous punch pair crossed midnight, advance subsequent punch in-time
                            if ($lastPunchTs !== null && $tsIn < $lastPunchTs && !preg_match('/^\d{4}-\d{2}-\d{2}/', $valIn)) {
                                $tsIn = strtotime('+1 day', $tsIn);
                            }

                            // Auto-advance out-time to next day if overnight shift is detected
                            if ($tsOut < $tsIn && !preg_match('/^\d{4}-\d{2}-\d{2}/', $valOut)) {
                                $tsOut = strtotime('+1 day', $tsOut);
                            }

                            if ($tsOut <= $tsIn) {
                                $punchErrors[] = "{$outHeader} ({$valOut}) must be greater than {$inHeader} ({$valIn}).";
                            } else {
                                $punchTimestamps[] = ['in' => $tsIn, 'out' => $tsOut];
                                $punchDateTimes[] = date('Y-m-d H:i:s', $tsIn);
                                $punchDateTimes[] = date('Y-m-d H:i:s', $tsOut);
                                $lastPunchTs = $tsOut;
                            }
                        }
                    }
                }

                if (!empty($punchErrors)) {
                    $this->addError($rowNumber, $data, $punchErrors);
                    continue;
                }

                if (empty($punchDateTimes)) {
                    $this->addError($rowNumber, $data, ['At least one punch in and punch out is required.']);
                    continue;
                }

                // 7. Calculate Total Working Hours from Valid Pairs
                $totalWorkingHours = null;
                if (!empty($punchTimestamps)) {
                    $totalMinutes = 0;
                    foreach ($punchTimestamps as $pair) {
                        $totalMinutes += (int) (($pair['out'] - $pair['in']) / 60);
                    }

                    if ($employee->shift) {
                        $shiftStart = Carbon::parse($employee->shift->shift_start_time);
                        $shiftEnd = Carbon::parse($employee->shift->shift_end_time);
                        if ($shiftEnd->lt($shiftStart)) {
                            $shiftEnd->addDay();
                        }
                        $maxShiftMinutes = (int) $shiftStart->diffInMinutes($shiftEnd);

                        if ($totalMinutes > $maxShiftMinutes) {
                            $maxHoursStr = sprintf('%02d:%02d', intdiv($maxShiftMinutes, 60), $maxShiftMinutes % 60);
                            $this->addError($rowNumber, $data, [
                                "Total worked time exceeds the maximum allowable shift duration ({$maxHoursStr} hrs) for shift '{$employee->shift->shift_name}'."
                            ]);
                            continue;
                        }
                    }

                    $hours = intdiv($totalMinutes, 60);
                    $minutes = $totalMinutes % 60;
                    $totalWorkingHours = round($hours + ($minutes / 100), 2);
                }

                // 8. Database Transaction (Sync Attendance & Punches)
                try {
                    DB::transaction(function () use ($employee, $formattedDate, $totalWorkingHours, $punchDateTimes, $userId): void {
                        $attendance = EmployeeAttendance::updateOrCreate(
                            [
                                'employee_id'     => $employee->id,
                                'attendance_date' => $formattedDate,
                            ],
                            [
                                'total_working_hours' => $totalWorkingHours,
                                'updated_by'          => $userId,
                            ]
                        );

                        if ($attendance->wasRecentlyCreated && $userId) {
                            $attendance->update(['created_by' => $userId]);
                        }

                        // Remove existing details and insert new entries
                        EmployeeAttendanceDetail::where('employee_attendance_id', $attendance->id)->delete();

                        if (!empty($punchDateTimes)) {
                            $details = [];
                            $now = Carbon::now();

                            foreach ($punchDateTimes as $inOutTime) {
                                $details[] = [
                                    'employee_attendance_id' => $attendance->id,
                                    'in_out_time'            => $inOutTime,
                                    'created_at'             => $now,
                                    'updated_at'             => $now,
                                ];
                            }

                            EmployeeAttendanceDetail::insert($details);
                        }
                    });

                    $importedRecords[$uniqueKey] = true;
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
     * Check if import had errors.
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Get error list.
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Generate rejected rows CSV and store it locally.
     */
    public function storeErrors(): string
    {
        $directory = 'employee-attendance-import-errors';
        if (!Storage::disk('local')->exists($directory)) {
            Storage::disk('local')->makeDirectory($directory);
        }

        $filename = 'employee-attendance-errors-' . time() . '.csv';
        $path = $directory . '/' . $filename;

        $handle = fopen('php://temp', 'r+');
        fputs($handle, "\xEF\xBB\xBF");

        $headers = !empty($this->fileHeaders) ? $this->fileHeaders : self::BASE_HEADERS;
        fputcsv($handle, array_merge($headers, ['error_reason']));

        foreach ($this->rawFailedRows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    /**
     * Stream CSV template for download.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $headers = ['date', 'employee_code', 'punch_1', 'punch_2', 'punch_3', 'punch_4'];
        $rows = [
            [date('Y-m-d'), 'EMP24-001', '09:00:00', '13:00:00', '14:00:00', '18:00:00'],
        ];

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, 'employee-attendance-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Normalize header row (strip BOM and trim to lowercase).
     */
    private function normalizeHeaders(array $headers): array
    {
        $headers[0] = preg_replace('/[\x{FEFF}\xEF\xBB\xBF]/u', '', (string) $headers[0]);
        return array_map(fn ($header) => strtolower(trim((string) $header)), $headers);
    }

    /**
     * Record a failed row with its specific error message.
     */
    private function addError(int $rowNumber, array $data, array $messages): void
    {
        $this->errors[] = [
            'row'    => $rowNumber,
            'errors' => $messages,
            'data'   => $data,
        ];
        $this->rawFailedRows[] = array_merge(array_values($data), [implode('; ', $messages)]);
    }
}