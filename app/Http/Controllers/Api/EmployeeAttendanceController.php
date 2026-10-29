<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\EmployeeAttendanceExport;
use App\Imports\EmployeeAttendanceImport;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceDetail;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EmployeeAttendanceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:employee-attendance-view|employee-attendance-create|employee-attendance-edit|employee-attendance-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:employee-attendance-create', ['only' => ['store']]);
        $this->middleware('permission:employee-attendance-edit',   ['only' => ['update']]);
        $this->middleware('permission:employee-attendance-delete', ['only' => ['destroy']]);
        $this->middleware('permission:employee-attendance-restore',['only' => ['restore']]);
        $this->middleware('permission:employee-attendance-export', ['only' => ['export']]);
        $this->middleware('permission:employee-attendance-import', ['only' => ['import', 'importTemplate', 'downloadTemplate', 'importStructure','importErrors']]);
    }

    /**
     * List attendance records with pagination and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 15);
        $employeeId = $request->query('employee_id');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $attendances = EmployeeAttendance::with(['employee:id,employee_name,employee_code', 'details'])
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($startDate, fn ($q) => $q->whereDate('attendance_date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('attendance_date', '<=', $endDate))
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->paginate($limit);

        return response()->json([
            'success' => true,
            'message' => 'Attendance list retrieved successfully.',
            'data' => $attendances->items(),
            'pagination' => [
                'current_page' => $attendances->currentPage(),
                'per_page' => $attendances->perPage(),
                'total' => $attendances->total(),
                'last_page' => $attendances->lastPage(),
            ],
        ], 200);
    }

    /**
     * Store or update an employee attendance record along with in_time and out_time.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id',
            'attendance_date' => 'required|date',
            'in_time' => 'required',
            'out_time' => 'required',
            'total_working_hours' => 'nullable|numeric|min:0|max:24',
        ]);

        $attendanceDate = null;
        $inCarbon = null;
        $outCarbon = null;

        $validator->after(function ($validator) use ($request, &$attendanceDate, &$inCarbon, &$outCarbon): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $attendanceDate = Carbon::parse($request->input('attendance_date'))->toDateString();
            } catch (\Throwable $e) {
                $validator->errors()->add('attendance_date', 'Invalid attendance date format.');
                return;
            }

            $inCarbon = $this->parseDateTime($attendanceDate, $request->input('in_time'));
            $outCarbon = $this->parseDateTime($attendanceDate, $request->input('out_time'));

            if (!$inCarbon) {
                $validator->errors()->add('in_time', 'Invalid in_time format.');
                return;
            }

            if (!$outCarbon) {
                $validator->errors()->add('out_time', 'Invalid out_time format.');
                return;
            }

            // Auto-advance out_time to next day if it's an overnight shift and date was not explicitly passed
            if ($outCarbon->lte($inCarbon) && !preg_match('/^\d{4}-\d{2}-\d{2}/', trim($request->input('out_time')))) {
                $outCarbon->addDay();
            }

            if ($outCarbon->lte($inCarbon)) {
                $validator->errors()->add('out_time', 'The out_time must be greater than in_time.');
            }

            $existingAttendance = EmployeeAttendance::where('employee_id', $request->input('employee_id'))
                ->where('attendance_date', $attendanceDate)
                ->first();

            if ($existingAttendance) {
                $lastDetail = $existingAttendance->details()
                    ->orderBy('in_out_time', 'desc')
                    ->first();

                if ($lastDetail) {
                    $lastPunchTime = Carbon::parse($lastDetail->in_out_time);
                    if ($inCarbon->lte($lastPunchTime)) {
                        $validator->errors()->add(
                            'in_time',
                            "New in_time ({$inCarbon->format('H:i:s')}) must be greater than the last recorded punch time ({$lastPunchTime->format('H:i:s')})."
                        );
                    }
                }

                $employee = Employee::with('shift')->find($request->input('employee_id'));
                if ($employee && $employee->shift && $inCarbon && $outCarbon) {
                    $shiftStart = Carbon::parse($employee->shift->shift_start_time);
                    $shiftEnd = Carbon::parse($employee->shift->shift_end_time);
                    if ($shiftEnd->lt($shiftStart)) {
                        $shiftEnd->addDay();
                    }
                    $maxShiftMinutes = (int) $shiftStart->diffInMinutes($shiftEnd);
                    $workedMinutes = (int) $inCarbon->diffInMinutes($outCarbon);

                    if ($workedMinutes > $maxShiftMinutes) {
                        $maxHoursStr = sprintf('%02d:%02d', intdiv($maxShiftMinutes, 60), $maxShiftMinutes % 60);
                        $validator->errors()->add(
                            'out_time',
                            "Total duration exceeds the assigned shift's maximum allowable duration ({$maxHoursStr} hrs) for '{$employee->shift->shift_name}'."
                        );
                    }
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $userId = $request->user()?->id;

        $attendance = DB::transaction(function () use ($validated, $inCarbon, $outCarbon, $userId) {
            $attendanceRecord = EmployeeAttendance::firstOrCreate(
                [
                    'employee_id' => $validated['employee_id'],
                    'attendance_date' => $validated['attendance_date'],
                ],
                [
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );

            if (!$attendanceRecord->wasRecentlyCreated) {
                $attendanceRecord->updated_by = $userId;
                $attendanceRecord->save();
            }

            // Append new punch details without wiping previous punches
            $detailsData = [];
            if ($inCarbon) {
                $detailsData[] = [
                    'employee_attendance_id' => $attendanceRecord->id,
                    'in_out_time' => $inCarbon->format('Y-m-d H:i:s'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($outCarbon) {
                $detailsData[] = [
                    'employee_attendance_id' => $attendanceRecord->id,
                    'in_out_time' => $outCarbon->format('Y-m-d H:i:s'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($detailsData)) {
                $attendanceRecord->details()->createMany($detailsData);
            }

            // Recalculate total_working_hours across all punches for this day            
            self::recalculateWorkingHours($attendanceRecord->id);
            

            return $attendanceRecord->load(['details', 'employee:id,employee_name,employee_code']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Attendance and punch details saved successfully.',
            'data' => $attendance,
        ], 201);
    }

    /**
     * Fetch a single attendance record by ID.
     */
    public function show(int $id): JsonResponse
    {
        $attendance = EmployeeAttendance::with(['employee', 'details', 'creator:id,name', 'updater:id,name'])
            ->find($id);

        if (!$attendance) {
            return response()->json(['success' => false, 'message' => 'Attendance record not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $attendance], 200);
    }

    /**
     * Update attendance record and punch details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // Master attendance record find karein (fallback agar route me detail ID pass ho rahi ho)
        $attendance = EmployeeAttendance::find($id);

        if (!$attendance) {
            $detail = EmployeeAttendanceDetail::find($id);
            if ($detail) {
                $attendance = EmployeeAttendance::find($detail->employee_attendance_id);
            }
        }

        if (!$attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance record not found.',
            ], 404);
        }

        // Validation
        $validator = Validator::make($request->all(), [
            'employee_id' => 'sometimes|required|integer|exists:employees,id',
            'attendance_date' => [
                'sometimes',
                'required',
                'date',
                Rule::unique('employee_attendances', 'attendance_date')
                    ->where(fn ($q) => $q->where('employee_id', $request->input('employee_id', $attendance->employee_id)))
                    ->ignore($attendance->id),
            ],
            'punches' => 'required|array|min:2',
            'punches.*.id' => 'nullable|integer|exists:employee_attendance_details,id',
            'punches.*.punch_no' => 'required|integer|min:1',
            'punches.*.punch_time' => 'required|string',
        ], [
            'punches.required' => 'At least one punch in and punch out is required.',
            'punches.min'      => 'At least one punch in and punch out is required.',
        ]);

        $attendanceDate = null;
        $parsedPunches = [];

        $validator->after(function ($validator) use ($request, $attendance, &$attendanceDate, &$parsedPunches): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $attendanceDate = Carbon::parse($request->input('attendance_date', $attendance->attendance_date))->toDateString();
            } catch (\Throwable $e) {
                $validator->errors()->add('attendance_date', 'Invalid attendance date format.');
                return;
            }

            $punches = $request->input('punches');

            if (!is_array($punches) || count($punches) < 2) {
                $validator->errors()->add('punches', 'At least one punch in and punch out is required.');
                return;
            }

            if (is_array($punches)) {
                // 1. Check punches count must be in pairs (Even number)
                if (count($punches) % 2 !== 0) {
                    $validator->errors()->add('punches', 'Punches must be in pairs (In and Out). Total punches count must be even.');
                    return;
                }

                // Punch numbers ke according sort/index karein
                $punchesByNo = [];
                foreach ($punches as $index => $punch) {
                    $pNo = (int) ($punch['punch_no'] ?? ($index + 1));
                    $punchesByNo[$pNo] = $punch;
                }

                ksort($punchesByNo);

                $maxPunchNo = max(array_keys($punchesByNo));
                $totalWorkedMinutes = 0;
                $lastPunchCarbon = null;

                // 2. Validate pairs (1-2, 3-4, 5-6, 7-8...)
                for ($i = 1; $i <= $maxPunchNo; $i += 2) {
                    $hasIn = isset($punchesByNo[$i]);
                    $hasOut = isset($punchesByNo[$i + 1]);

                    if ($hasIn && !$hasOut) {
                        $validator->errors()->add('punches', "Punch-{$i} requires a matching pair Punch-" . ($i + 1) . ".");
                    } elseif (!$hasIn && $hasOut) {
                        $validator->errors()->add('punches', "Punch-" . ($i + 1) . " cannot exist without Punch-{$i}.");
                    } elseif ($hasIn && $hasOut) {
                        $inTimeStr = trim((string) ($punchesByNo[$i]['punch_time'] ?? ''));
                        $outTimeStr = trim((string) ($punchesByNo[$i + 1]['punch_time'] ?? ''));

                        $inCarbon = $this->parseDateTime($attendanceDate, $inTimeStr);
                        $outCarbon = $this->parseDateTime($attendanceDate, $outTimeStr);

                        if (!$inCarbon) {
                            $validator->errors()->add('punches', "Invalid time format for Punch-{$i}: '{$inTimeStr}'.");
                        }
                        if (!$outCarbon) {
                            $validator->errors()->add('punches', "Invalid time format for Punch-" . ($i + 1) . ": '{$outTimeStr}'.");
                        }

                        // Advance in_time if previous punch pair already crossed midnight
                        if ($inCarbon && $lastPunchCarbon && $inCarbon->lt($lastPunchCarbon) && !preg_match('/^\d{4}-\d{2}-\d{2}/', $inTimeStr)) {
                            $inCarbon->addDay();
                        }

                        // Auto-advance out_time to next day if overnight shift is detected
                        if ($inCarbon && $outCarbon && $outCarbon->lt($inCarbon) && !preg_match('/^\d{4}-\d{2}-\d{2}/', $outTimeStr)) {
                            $outCarbon->addDay();
                        }

                        if ($inCarbon && $outCarbon && $outCarbon->lt($inCarbon)) {
                            $validator->errors()->add('punches', "Punch-" . ($i + 1) . " ({$outTimeStr}) cannot be earlier than Punch-{$i} ({$inTimeStr}).");
                        }

                        if ($inCarbon && $outCarbon) {
                            $totalWorkedMinutes += (int) $inCarbon->diffInMinutes($outCarbon);
                            $lastPunchCarbon = clone $outCarbon;

                            $parsedPunches[] = [
                                'id'          => $punchesByNo[$i]['id'] ?? null,
                                'in_out_time' => $inCarbon->format('Y-m-d H:i:s'),
                            ];
                            $parsedPunches[] = [
                                'id'          => $punchesByNo[$i + 1]['id'] ?? null,
                                'in_out_time' => $outCarbon->format('Y-m-d H:i:s'),
                            ];
                        }
                    }
                }

                // Validate total worked duration against employee's assigned shift
                if ($validator->errors()->isEmpty()) {
                    $employeeId = $request->input('employee_id', $attendance->employee_id);
                    $employee = Employee::with('shift')->find($employeeId);
                    if ($employee && $employee->shift && $employee->shift->shift_start_time && $employee->shift->shift_end_time) {
                        $shiftStart = Carbon::parse($employee->shift->shift_start_time);
                        $shiftEnd = Carbon::parse($employee->shift->shift_end_time);
                        if ($shiftEnd->lt($shiftStart)) {
                            $shiftEnd->addDay();
                        }
                        $maxShiftMinutes = (int) $shiftStart->diffInMinutes($shiftEnd);
                        if ($totalWorkedMinutes > $maxShiftMinutes) {
                            $maxHoursStr = sprintf('%02d:%02d', intdiv($maxShiftMinutes, 60), $maxShiftMinutes % 60);
                            $validator->errors()->add(
                                'punches',
                                "Total duration exceeds the assigned shift's maximum allowable duration ({$maxHoursStr} hrs) for '{$employee->shift->shift_name}'."
                            );
                        }
                    }
                }

                if (empty($parsedPunches) && $validator->errors()->isEmpty()) {
                    $validator->errors()->add('punches', 'At least one punch in and punch out is required.');
                    return;
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $userId = $request->user()?->id;

        $updatedAttendance = DB::transaction(function () use ($attendance, $validated, $attendanceDate, $parsedPunches, $userId) {
            // 1. Update Master Attendance Record
            $attendance->update([
                'employee_id'     => $validated['employee_id'] ?? $attendance->employee_id,
                'attendance_date' => $attendanceDate ?? $attendance->attendance_date,
                'updated_by'      => $userId,
            ]);

            // 2. Sync Punches
            if (!empty($parsedPunches)) {
                $payloadDetailIds = collect($parsedPunches)
                    ->pluck('id')
                    ->filter()
                    ->toArray();

                // Jo details payload me nahi hain unhe delete karein
                $detailQuery = EmployeeAttendanceDetail::where('employee_attendance_id', $attendance->id);
                if (!empty($payloadDetailIds)) {
                    $detailQuery->whereNotIn('id', $payloadDetailIds);
                }
                $detailQuery->delete();

                // Details ko update ya create karein
                foreach ($parsedPunches as $punchData) {
                    if (!empty($punchData['id'])) {
                        EmployeeAttendanceDetail::where('id', $punchData['id'])
                            ->where('employee_attendance_id', $attendance->id)
                            ->update([
                                'in_out_time' => $punchData['in_out_time'],
                                'updated_at'  => now(),
                            ]);
                    } else {
                        EmployeeAttendanceDetail::create([
                            'employee_attendance_id' => $attendance->id,
                            'in_out_time'            => $punchData['in_out_time'],
                            'created_at'             => now(),
                            'updated_at'             => now(),
                        ]);
                    }
                }
            }

            // 3. Recalculate working hours based on updated punches
            self::recalculateWorkingHours($attendance->id);

            $attendance->refresh();

            return $attendance->load([
                'details' => fn ($q) => $q->orderBy('in_out_time', 'asc'),
                'employee:id,employee_name,employee_code',
                'updater:id,name',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Attendance record and punch details updated successfully.',
            'data'    => $updatedAttendance,
        ], 200);
    }

    /**
     * Recalculate total working hours based on all details for an attendance record.
     */
    public static function recalculateWorkingHours($id): float
    {
        $attendance = $id instanceof EmployeeAttendance ? $id : EmployeeAttendance::find($id);
        if (!$attendance) {
            return 0;
        }

        $details = $attendance->details()
            ->orderBy('in_out_time', 'asc')
            ->get();

        $details = $details->unique('in_out_time')->values();

        $totalMinutes = 0;
        $count = $details->count();

        for ($i = 0; $i < $count - 1; $i += 2) {
            $inTime = Carbon::parse($details[$i]->in_out_time);
            $outTime = Carbon::parse($details[$i + 1]->in_out_time);

            if ($outTime->lt($inTime)) {
                $outTime->addDay();
            }

            $totalMinutes += (int) $inTime->diffInMinutes($outTime);
        }

        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;
        $totalHours = round($hours + ($minutes / 100), 2);
        $attendance->update(['total_working_hours' => $totalHours]);

        return $totalHours;
    }

    /**
     * Delete an attendance record.
     */
    public function destroy(int $id): JsonResponse
    {
        $attendance = EmployeeAttendance::find($id);

        if (!$attendance) {
            return response()->json(['success' => false, 'message' => 'Attendance record not found.'], 404);
        }

        $attendance->delete();

        return response()->json([
            'success' => true,
            'message' => 'Attendance record deleted successfully.',
        ], 200);
    }

    /**
     * Export attendance records as CSV.
     */
    public function export(Request $request)
    {
        $exporter = new EmployeeAttendanceExport();
        return $exporter->download($request);
    }

    /**
     * Import employee attendance records from a CSV file.
     */
    public function import(Request $request): JsonResponse
    {
        $templateUrl = route('employee-attendances.import.template');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $import = new EmployeeAttendanceImport();

        try {
            $imported = $import->import($request->file('file'));

            if ($import->hasErrors()) {
                $errorFile = $import->storeErrors();

                return response()->json([
                    'success'      => false,
                    'message'      => 'Some attendance records could not be imported.',
                    'imported'     => $imported,
                    'failed'       => count($import->errors()),
                    'errors'       => $import->errors(),
                    'errors_url'   => route('employee-attendances.import.errors', ['filename' => basename($errorFile)]),
                    'template_url' => $templateUrl,
                ], 422);
            }

            return response()->json([
                'success'      => true,
                'message'      => 'Attendance records imported successfully.',
                'imported'     => $imported,
                'failed'       => 0,
                'errors'       => [],
                'template_url' => $templateUrl,
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import attendance records.',
                'error'   => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Download CSV containing errors from a previous attendance import.
     */
    public function importErrors(string $filename)
    {
        $path = 'employee-attendance-import-errors/' . basename($filename);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Import error file not found.');

        return response()->download(
            Storage::disk('local')->path($path),
            'employee-attendance-errors.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    /**
     * Return the schema/structure expected by the attendance import CSV.
     */
    public function importStructure(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Employee attendance import structure retrieved successfully.',
            'data'    => [
                'headers' => ['date', 'employee_code', 'punch_1', 'punch_2', 'punch_3', 'punch_4'],
                'columns' => [
                    ['name' => 'date', 'type' => 'date', 'required' => true, 'description' => 'Attendance date in YYYY-MM-DD format.'],
                    ['name' => 'employee_code', 'type' => 'string', 'required' => true, 'description' => 'Existing employee code.'],
                    ['name' => 'punch_1', 'type' => 'time', 'required' => false, 'description' => 'Punch In time (e.g. 09:00:00).'],
                    ['name' => 'punch_2', 'type' => 'time', 'required' => false, 'description' => 'Punch Out time (e.g. 13:00:00).'],
                    ['name' => 'punch_3', 'type' => 'time', 'required' => false, 'description' => 'Punch In time (e.g. 14:00:00).'],
                    ['name' => 'punch_4', 'type' => 'time', 'required' => false, 'description' => 'Punch Out time (e.g. 18:00:00).'],
                ],
                'template_url' => route('employee-attendances.import.template'),
            ],
        ]);
    }

    /**
     * Download CSV template for attendance import.
     */
    public function importTemplate()
    {
        return (new EmployeeAttendanceImport())->downloadTemplate();
    }

    /**
     * Alias for importTemplate.
     */
    public function downloadTemplate()
    {
        return $this->importTemplate();
    }

    /**
     * Parse date and time into a Carbon instance.
     */
    private function parseDateTime(string $date, ?string $time): ?Carbon
    {
        if (!$time || trim($time) === '') {
            return null;
        }

        $time = trim($time);

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $time)
            ? Carbon::parse($time)
            : Carbon::parse("{$date} {$time}");
    }

    /**
     * Calculate working hours between in_time and out_time.
     */
    private function calculateWorkingHours(Carbon $inTime, Carbon $outTime): float
    {
        $out = clone $outTime;
        if ($out->lt($inTime)) {
            $out->addDay();
        }

        $totalMinutes = (int) $inTime->diffInMinutes($out);
        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;

        return round($hours + ($minutes / 100), 2);
    }
}