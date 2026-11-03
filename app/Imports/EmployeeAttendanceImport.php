<?php

namespace App\Imports;

use App\Http\Traits\HandlesImport;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceDetail;
use App\Models\Holiday;
use App\Models\StoreWeeklyOff;
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
     * Cache for store weekly offs by location ID.
     *
     * @var array<int, array<string>>
     */
    protected array $storeWeeklyOffCache = [];

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
            $holidayCache = [];
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
                $empCode = trim((string) ($data['employee_code'] ?? ''));
                $lowerEmpCode = mb_strtolower($empCode);
                $uniqueKey = $lowerEmpCode . '_' . $dateVal;

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

                $formattedDate = date('Y-m-d', strtotime($dateVal));

                // Holiday Check
                if (!array_key_exists($formattedDate, $holidayCache)) {
                    $holidayCache[$formattedDate] = Holiday::where('is_delete', false)
                        ->where('is_active', true)
                        ->whereDate('holiday_date', $formattedDate)
                        ->first();
                }

                $holiday = $holidayCache[$formattedDate];

                if ($holiday) {
                    $this->addError($rowNumber, $data, ["Attendance cannot be marked on a holiday ({$holidayCache[$formattedDate]->holiday_title})."]);
                    continue;
                }

                // 5. In-Memory Employee Cache Lookup
                if (!array_key_exists($lowerEmpCode, $employeeCache)) {
                    $employeeCache[$lowerEmpCode] = Employee::withTrashed()
                        ->with(['shift', 'location'])
                        ->whereRaw('LOWER(employee_code) = ?', [$lowerEmpCode])
                        ->first();
                }

                $employee = $employeeCache[$lowerEmpCode];
                if (!$employee) {
                    $this->addError($rowNumber, $data, ["Employee with code '{$empCode}' does not exist."]);
                    continue;
                }
               

                if ($employee->trashed() || (bool) $employee->is_delete) {
                    $this->addError($rowNumber, $data, ["Employee with code '{$empCode}' has been deleted."]);
                    continue;
                }

                if (!(bool) $employee->is_active) {
                    $this->addError($rowNumber, $data, ["Employee with code '{$empCode}' is inactive and cannot have attendance recorded."]);
                    continue;
                }

                if (!empty($employee->date_of_join) && Carbon::parse($formattedDate)->lt(Carbon::parse($employee->date_of_join))) {
                    $this->addError($rowNumber, $data, ["Attendance date '{$formattedDate}' is before the employee's date of join ({$employee->date_of_join})."]);
                    continue;
                }

                if (!empty($employee->date_of_leave) && Carbon::parse($formattedDate)->gt(Carbon::parse($employee->date_of_leave))) {
                    $this->addError($rowNumber, $data, ["Attendance date '{$formattedDate}' is after the employee's date of leave ({$employee->date_of_leave})."]);
                    continue;
                }

                if ($this->isEmployeeWeeklyOff($employee, $formattedDate)) {
                    $dayOfWeek = Carbon::parse($formattedDate)->format('l');
                    $this->addError($rowNumber, $data, ["Attendance cannot be marked on the employee's weekly off day ({$dayOfWeek})."]);
                    continue;
                }

                $punchErrors = [];
                $punchTimestamps = [];
                $punchDateTimes = [];
                $lastPunchTs = null;
                $shiftStart = null;
                $shiftEnd = null;

                if (
                    $employee->shift
                    && $employee->shift->shift_start_time
                    && $employee->shift->shift_end_time
                ) {
                    $shiftStartTime = Carbon::parse($employee->shift->shift_start_time)->format('H:i:s');
                    $shiftEndTime = Carbon::parse($employee->shift->shift_end_time)->format('H:i:s');
                    $shiftStart = Carbon::parse("{$formattedDate} {$shiftStartTime}");
                    $shiftEnd = Carbon::parse("{$formattedDate} {$shiftEndTime}");

                    if ($shiftEnd->lt($shiftStart)) {
                        $shiftEnd->addDay();
                    }
                }

                // Check existing attendance and punches in database for this employee and date
                $existingAttendance = EmployeeAttendance::with('details')
                    ->where('employee_id', $employee->id)
                    ->whereDate('attendance_date', $formattedDate)
                    ->first();

                $existingPunches = [];
                $existingPunchTimes = [];

                if ($existingAttendance && $existingAttendance->details->isNotEmpty()) {
                    $existingPunches = $existingAttendance->details
                        ->map(fn ($d) => Carbon::parse($d->in_out_time)->format('Y-m-d H:i:s'))
                        ->all();
                    $existingPunchTimes = $existingAttendance->details
                        ->map(fn ($d) => Carbon::parse($d->in_out_time)->format('H:i:s'))
                        ->all();
                }

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
                            if ($tsIn === $tsOut) {
                                $punchErrors[] = "Duplicate punch: {$inHeader} and {$outHeader} have the same time '{$valIn}'.";
                            }

                            // If previous punch pair crossed midnight, advance subsequent punch in-time
                            if ($lastPunchTs !== null && $tsIn < $lastPunchTs && !preg_match('/^\d{4}-\d{2}-\d{2}/', $valIn)) {
                                $tsIn = strtotime('+1 day', $tsIn);
                            }

                            if ($lastPunchTs !== null && $tsIn <= $lastPunchTs) {
                                $punchErrors[] = $tsIn === $lastPunchTs
                                    ? "Duplicate punch: {$inHeader} ('{$valIn}') is identical to the previous punch out time."
                                    : "{$inHeader} ('{$valIn}') must be greater than the previous punch out time.";
                            }

                            // Auto-advance out-time to next day if overnight shift is detected
                            if ($tsOut < $tsIn && !preg_match('/^\d{4}-\d{2}-\d{2}/', $valOut)) {
                                $tsOut = strtotime('+1 day', $tsOut);
                            }

                            if ($shiftStart && $shiftEnd) {
                                if ($tsIn < $shiftStart->timestamp || $tsIn > $shiftEnd->timestamp) {
                                    $punchErrors[] = "{$inHeader} ({$valIn}) is outside the assigned shift ({$shiftStart->format('Y-m-d H:i:s')} - {$shiftEnd->format('Y-m-d H:i:s')}).";
                                }
                                if ($tsOut < $shiftStart->timestamp || $tsOut > $shiftEnd->timestamp) {
                                    $punchErrors[] = "{$outHeader} ({$valOut}) is outside the assigned shift ({$shiftStart->format('Y-m-d H:i:s')} - {$shiftEnd->format('Y-m-d H:i:s')}).";
                                }
                            }

                            if ($tsOut <= $tsIn) {
                                $punchErrors[] = "{$outHeader} ({$valOut}) must be greater than {$inHeader} ({$valIn}).";
                            }

                            $formattedTsIn = date('Y-m-d H:i:s', $tsIn);
                            $formattedTsOut = date('Y-m-d H:i:s', $tsOut);
                            $timeIn = date('H:i:s', $tsIn);
                            $timeOut = date('H:i:s', $tsOut);

                            $inDbDuplicate = in_array($formattedTsIn, $existingPunches, true) || in_array($timeIn, $existingPunchTimes, true);
                            $outDbDuplicate = in_array($formattedTsOut, $existingPunches, true) || in_array($timeOut, $existingPunchTimes, true);

                            if ($inDbDuplicate && $outDbDuplicate) {
                                $punchErrors[] = "Duplicate punch: Both {$inHeader} ('{$valIn}') and {$outHeader} ('{$valOut}') already exist in database on date {$formattedDate}.";
                            } elseif ($inDbDuplicate) {
                                $punchErrors[] = "Duplicate punch: {$inHeader} ('{$valIn}') already exists in database on date {$formattedDate}.";
                            } elseif ($outDbDuplicate) {
                                $punchErrors[] = "Duplicate punch: {$outHeader} ('{$valOut}') already exists in database on date {$formattedDate}.";
                            }

                            if (in_array($formattedTsIn, $punchDateTimes, true)) {
                                $punchErrors[] = "Duplicate punch: {$inHeader} ('{$valIn}') is repeated on date {$formattedDate}.";
                            }
                            if (in_array($formattedTsOut, $punchDateTimes, true)) {
                                $punchErrors[] = "Duplicate punch: {$outHeader} ('{$valOut}') is repeated on date {$formattedDate}.";
                            }

                            if (empty($punchErrors)) {
                                $punchTimestamps[] = ['in' => $tsIn, 'out' => $tsOut];
                                $punchDateTimes[] = $formattedTsIn;
                                $punchDateTimes[] = $formattedTsOut;
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

                    if ($shiftStart && $shiftEnd) {
                        $maxShiftMinutes = (int) $shiftStart->diffInMinutes($shiftEnd);

                        if ($holiday && $holiday->holiday_type === 'half day') {
                            $halfDayMaxMinutes = (int) ($maxShiftMinutes / 2);
                            if ($totalMinutes > $halfDayMaxMinutes) {
                                $halfHoursStr = sprintf('%02d:%02d', intdiv($halfDayMaxMinutes, 60), $halfDayMaxMinutes % 60);
                                $this->addError($rowNumber, $data, [
                                    "On a half-day holiday ({$holiday->holiday_title}), total worked duration cannot exceed {$halfHoursStr} hrs."
                                ]);
                                continue;
                            }
                        }

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

    /**
     * Check if a given date is a weekly off day for the employee.
     */
    private function isEmployeeWeeklyOff(Employee $employee, string $formattedDate): bool
    {
        $dayOfWeek = strtolower(Carbon::parse($formattedDate)->format('l'));

        // 1. Direct employee weekly off check
        if (!empty($employee->weekly_off)) {
            $weeklyOffDays = array_map('trim', explode(',', strtolower($employee->weekly_off)));
            if (in_array($dayOfWeek, $weeklyOffDays, true)) {
                return true;
            }
        }

        // 2. Store & Location weekly off check (cached for bulk performance)
        if ($employee->location_id) {
            if (!array_key_exists($employee->location_id, $this->storeWeeklyOffCache)) {
                $locationOffs = StoreWeeklyOff::where('location_id', $employee->location_id)
                    ->where('is_active', true)
                    ->where('is_delete', false)
                    ->pluck('weekly_off_day')
                    ->map(fn ($d) => strtolower(trim($d)))
                    ->all();

                if ($employee->location && !empty($employee->location->weekly_off_day)) {
                    $locDays = array_map('trim', explode(',', strtolower($employee->location->weekly_off_day)));
                    $locationOffs = array_unique(array_merge($locationOffs, $locDays));
                }

                $this->storeWeeklyOffCache[$employee->location_id] = $locationOffs;
            }

            if (in_array($dayOfWeek, $this->storeWeeklyOffCache[$employee->location_id], true)) {
                return true;
            }
        }

        return false;
    }
}