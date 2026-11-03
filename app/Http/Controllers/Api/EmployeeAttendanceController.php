<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\GeneratesSignedImportErrorUrl;
use App\Exports\EmployeeAttendanceExport;
use App\Imports\EmployeeAttendanceImport;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceDetail;
use App\Models\Holiday;
use App\Models\StoreWeeklyOff;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EmployeeAttendanceController extends Controller
{
    use GeneratesSignedImportErrorUrl;

    public function __construct()
    {
        $this->middleware('auth:sanctum', ['except' => ['importErrors']]);
        $this->middleware('permission:employee_attendance-view|employee_attendance-create|employee_attendance-edit|employee_attendance-delete', ['only' => ['index', 'show']]);
        $this->middleware('permission:employee_attendance-create', ['only' => ['store']]);
        $this->middleware('permission:employee_attendance-edit',   ['only' => ['update']]);
        $this->middleware('permission:employee_attendance-delete', ['only' => ['destroy']]);
        $this->middleware('permission:employee_attendance-restore',['only' => ['restore']]);
        $this->middleware('permission:employee_attendance-export', ['only' => ['export']]);
        $this->middleware('permission:employee_attendance-import', ['only' => ['import', 'importTemplate', 'downloadTemplate', 'importStructure']]);
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
        if ($request->has('punches') && is_array($request->input('punches'))) {
            $punches = array_values($request->input('punches'));
            if (isset($punches[0]['punch_time']) && !$request->filled('in_time')) {
                $request->merge(['in_time' => $punches[0]['punch_time']]);
            }
            if (isset($punches[1]['punch_time']) && !$request->filled('out_time')) {
                $request->merge(['out_time' => $punches[1]['punch_time']]);
            }
        }

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

            // 1. Employee Validation (Moved up for better performance)
            $employee = Employee::withTrashed()->with(['shift', 'location'])->find($request->input('employee_id'));

            if (!$employee || $employee->trashed() || (bool) $employee->is_delete) {
                $validator->errors()->add('employee_id', 'The selected employee has been deleted.');
                return;
            }

            if (!(bool) $employee->is_active) {
                $validator->errors()->add('employee_id', 'The selected employee is inactive and cannot have attendance recorded.');
                return;
            }

            if (!empty($employee->date_of_join) && Carbon::parse($attendanceDate)->lt(Carbon::parse($employee->date_of_join))) {
                $validator->errors()->add('attendance_date', "Attendance date '{$attendanceDate}' is before the employee's date of join ({$employee->date_of_join}).");
                return;
            }

            if (!empty($employee->date_of_leave) && Carbon::parse($attendanceDate)->gt(Carbon::parse($employee->date_of_leave))) {
                $validator->errors()->add('attendance_date', "Attendance date '{$attendanceDate}' is after the employee's date of leave ({$employee->date_of_leave}).");
                return;
            }

            // 2. Weekly Off Check
            if ($this->isEmployeeWeeklyOff($employee, $attendanceDate)) {
                $dayOfWeek = Carbon::parse($attendanceDate)->format('l');
                $validator->errors()->add('attendance_date', "Attendance cannot be marked on the employee's weekly off day ({$dayOfWeek}).");
                return;
            }

            // 3. Holiday Check (FIXED: Allow half-day holidays to pass through)
            $holiday = Holiday::where('is_delete', false)
                ->where('is_active', true)
                ->whereDate('holiday_date', $attendanceDate)
                ->first();

            if ($holiday && $holiday->holiday_type !== 'half day') {
                $validator->errors()->add('attendance_date', "Attendance cannot be marked on a full-day holiday ({$holiday->holiday_title}).");
                return;
            }

            // 4. Time Parsing & Checks
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

            // Duplicate punch: in_time and out_time cannot be identical
            if ($inCarbon->eq($outCarbon)) {
                $validator->errors()->add('out_time', "Duplicate punch: in_time and out_time cannot be identical ({$inCarbon->format('H:i:s')}).");
                return;
            }

            // Auto-advance out_time to next day if it's an overnight shift
            if ($outCarbon->lt($inCarbon) && !preg_match('/^\d{4}-\d{2}-\d{2}/', trim($request->input('out_time')))) {
                $outCarbon->addDay();
            }

            if ($outCarbon->lte($inCarbon)) {
                $validator->errors()->add('out_time', 'The out_time must be greater than in_time.');
                return;
            }

            // 5. Existing Punches Validation
            $existingAttendance = EmployeeAttendance::where('employee_id', $request->input('employee_id'))
                ->whereDate('attendance_date', $attendanceDate)
                ->first();

            if ($existingAttendance) {
                $existingDetails = $existingAttendance->details()->get();

                $existingPunches = $existingDetails
                    ->map(fn ($d) => Carbon::parse($d->in_out_time)->format('Y-m-d H:i:s'))
                    ->all();
                $existingPunchTimes = $existingDetails
                    ->map(fn ($d) => Carbon::parse($d->in_out_time)->format('H:i:s'))
                    ->all();

                $formattedIn = $inCarbon->format('Y-m-d H:i:s');
                $formattedOut = $outCarbon->format('Y-m-d H:i:s');
                $timeIn = $inCarbon->format('H:i:s');
                $timeOut = $outCarbon->format('H:i:s');

                $inExists = in_array($formattedIn, $existingPunches, true) || in_array($timeIn, $existingPunchTimes, true);
                $outExists = in_array($formattedOut, $existingPunches, true) || in_array($timeOut, $existingPunchTimes, true);

                if ($inExists && $outExists) {
                    $validator->errors()->add('in_time', "Duplicate punch: Both in_time ({$timeIn}) and out_time ({$timeOut}) already exist on date {$attendanceDate}.");
                    $validator->errors()->add('out_time', "Duplicate punch: Both in_time ({$timeIn}) and out_time ({$timeOut}) already exist on date {$attendanceDate}.");
                } elseif ($inExists) {
                    $validator->errors()->add('in_time', "Duplicate punch: in_time ({$timeIn}) already exists on date {$attendanceDate}.");
                } elseif ($outExists) {
                    $validator->errors()->add('out_time', "Duplicate punch: out_time ({$timeOut}) already exists on date {$attendanceDate}.");
                }

                if (!$inExists && !$outExists) {
                    $lastDetail = $existingDetails->sortByDesc('in_out_time')->first();

                    if ($lastDetail) {
                        $lastPunchTime = Carbon::parse($lastDetail->in_out_time);
                        if ($inCarbon->lte($lastPunchTime)) {
                            $validator->errors()->add(
                                'in_time',
                                $inCarbon->eq($lastPunchTime)
                                    ? "Duplicate punch: in_time ({$inCarbon->format('H:i:s')}) is identical to the last recorded punch time."
                                    : "New in_time ({$inCarbon->format('H:i:s')}) must be greater than the last recorded punch time ({$lastPunchTime->format('H:i:s')})."
                            );
                        }
                    }
                }
            }

            // 6. Shift Bounds & Half-Day Hours Limit Validation
            $shiftBounds = $this->getShiftBounds($employee, $attendanceDate);
            if ($shiftBounds && $inCarbon && $outCarbon) {
                [$shiftStart, $shiftEnd] = $shiftBounds;
                if ($inCarbon->lt($shiftStart) || $inCarbon->gt($shiftEnd)) {
                    $validator->errors()->add(
                        'in_time',
                        "The in_time must be within the assigned shift ({$shiftStart->format('Y-m-d H:i:s')} - {$shiftEnd->format('Y-m-d H:i:s')})."
                    );
                }
                if ($outCarbon->lt($shiftStart) || $outCarbon->gt($shiftEnd)) {
                    $validator->errors()->add(
                        'out_time',
                        "The out_time must be within the assigned shift ({$shiftStart->format('Y-m-d H:i:s')} - {$shiftEnd->format('Y-m-d H:i:s')})."
                    );
                }

                $maxShiftMinutes = (int) $shiftStart->diffInMinutes($shiftEnd);
                $workedMinutes = (int) $inCarbon->diffInMinutes($outCarbon);

                if ($holiday && $holiday->holiday_type === 'half day') {
                    $halfDayMaxMinutes = (int) ($maxShiftMinutes / 2);
                    if ($workedMinutes > $halfDayMaxMinutes) {
                        $halfHoursStr = sprintf('%02d:%02d', intdiv($halfDayMaxMinutes, 60), $halfDayMaxMinutes % 60);
                        $validator->errors()->add(
                            'out_time',
                            "On a half-day holiday ({$holiday->holiday_title}), total duration cannot exceed {$halfHoursStr} hrs."
                        );
                    }
                }

                if ($workedMinutes > $maxShiftMinutes) {
                    $maxHoursStr = sprintf('%02d:%02d', intdiv($maxShiftMinutes, 60), $maxShiftMinutes % 60);
                    $validator->errors()->add(
                        'out_time',
                        "Total duration exceeds the assigned shift's maximum allowable duration ({$maxHoursStr} hrs) for '{$employee->shift->shift_name}'."
                    );
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

        $attendance = DB::transaction(function () use ($validated, $inCarbon, $outCarbon, $attendanceDate, $userId) {
            $attendanceRecord = EmployeeAttendance::firstOrCreate(
                [
                    'employee_id' => $validated['employee_id'],
                    'attendance_date' => $attendanceDate,
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

            // Fixed method call
            $this->recalculateWorkingHours($attendanceRecord->id);

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

        // Fallback: Agar frontend se direct in_time/out_time bheja gaya ho toh use punches format me map karein
        if (!$request->has('punches') && $request->filled('in_time') && $request->filled('out_time')) {
            $existingDetails = $attendance->details()->orderBy('in_out_time', 'asc')->get();
            $request->merge([
                'punches' => [
                    [
                        'id'         => $existingDetails->get(0)?->id,
                        'punch_no'   => 1,
                        'punch_time' => $request->input('in_time'),
                    ],
                    [
                        'id'         => $existingDetails->get(1)?->id,
                        'punch_no'   => 2,
                        'punch_time' => $request->input('out_time'),
                    ],
                ],
            ]);
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
            'punches.*.id' => [
                'nullable',
                'integer',
                'distinct',
                Rule::exists('employee_attendance_details', 'id')->where('employee_attendance_id', $attendance->id),
            ],
            'punches.*.punch_no' => 'required|integer|min:1|distinct',
            'punches.*.punch_time' => 'required|string',
        ], [
            'punches.required' => 'At least one punch in and punch out is required.',
            'punches.min'      => 'At least one punch in and punch out is required.',
            'punches.*.punch_no.distinct' => 'Duplicate punch_no detected in punches list.',
            'punches.*.id.distinct'       => 'Duplicate punch detail ID detected in punches list.',
            'punches.*.id.exists'         => 'The selected punch detail ID does not belong to this attendance record.',
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

            $targetEmployeeId = (int) $request->input('employee_id', $attendance->employee_id);
            $duplicateAttendance = EmployeeAttendance::where('employee_id', $targetEmployeeId)
                ->whereDate('attendance_date', $attendanceDate)
                ->where('id', '!=', $attendance->id)
                ->exists();

            if ($duplicateAttendance) {
                $validator->errors()->add('attendance_date', "Duplicate attendance: An attendance record already exists for this employee on date {$attendanceDate}.");
                return;
            }

            $holiday = Holiday::where('is_delete', false)
                ->where('is_active', true)
                ->whereDate('holiday_date', $attendanceDate)
                ->first();

            if ($holiday && $holiday->holiday_type !== 'half day') {
                $validator->errors()->add('attendance_date', "Attendance cannot be marked on a holiday ({$holiday->holiday_title}).");
                return;
            }

            $punches = $request->input('punches');
            $employeeId = $request->input('employee_id', $attendance->employee_id);
            $employee = Employee::withTrashed()->with(['shift', 'location'])->find($employeeId);

            if (!$employee || $employee->trashed() || (bool) $employee->is_delete) {
                $validator->errors()->add('employee_id', 'The selected employee has been deleted.');
                return;
            }

            if (!(bool) $employee->is_active) {
                $validator->errors()->add('employee_id', 'The selected employee is inactive and cannot have attendance recorded.');
                return;
            }

            if ($employee && !empty($employee->date_of_join) && Carbon::parse($attendanceDate)->lt(Carbon::parse($employee->date_of_join))) {
                $validator->errors()->add('attendance_date', "Attendance date '{$attendanceDate}' is before the employee's date of join ({$employee->date_of_join}).");
                return;
            }

            if ($employee && !empty($employee->date_of_leave) && Carbon::parse($attendanceDate)->gt(Carbon::parse($employee->date_of_leave))) {
                $validator->errors()->add('attendance_date', "Attendance date '{$attendanceDate}' is after the employee's date of leave ({$employee->date_of_leave}).");
                return;
            }

            if ($employee && $this->isEmployeeWeeklyOff($employee, $attendanceDate)) {
                $dayOfWeek = Carbon::parse($attendanceDate)->format('l');
                $validator->errors()->add('attendance_date', "Attendance cannot be marked on the employee's weekly off day ({$dayOfWeek}).");
                return;
            }

            $shiftBounds = $this->getShiftBounds($employee, $attendanceDate);

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
                $seenPunchDateTimes = [];

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

                        if ($inCarbon && $outCarbon) {
                            $isPairDuplicate = false;

                            if ($inCarbon->eq($outCarbon)) {
                                $validator->errors()->add('punches', "Duplicate punch: Punch-{$i} and Punch-" . ($i + 1) . " have the same time ({$inTimeStr}).");
                                $isPairDuplicate = true;
                            }

                            // Advance in_time if previous punch pair already crossed midnight
                            if ($lastPunchCarbon && $inCarbon->lt($lastPunchCarbon) && !preg_match('/^\d{4}-\d{2}-\d{2}/', $inTimeStr)) {
                                $inCarbon->addDay();
                            }

                            $isSequentialDuplicate = false;
                            if ($lastPunchCarbon && $inCarbon->lte($lastPunchCarbon)) {
                                if ($inCarbon->eq($lastPunchCarbon)) {
                                    $validator->errors()->add('punches', "Duplicate punch: Punch-{$i} ({$inTimeStr}) is identical to Punch-" . ($i - 1) . " ({$lastPunchCarbon->format('H:i:s')}).");
                                    $isSequentialDuplicate = true;
                                } else {
                                    $validator->errors()->add('punches', "Punch-{$i} ({$inTimeStr}) must be greater than Punch-" . ($i - 1) . " ({$lastPunchCarbon->format('H:i:s')}).");
                                }
                            }

                            // Auto-advance out_time to next day if overnight shift is detected
                            if ($outCarbon->lt($inCarbon) && !preg_match('/^\d{4}-\d{2}-\d{2}/', $outTimeStr)) {
                                $outCarbon->addDay();
                            }

                            if ($outCarbon->lte($inCarbon) && !$isPairDuplicate) {
                                $validator->errors()->add('punches', "Punch-" . ($i + 1) . " ({$outTimeStr}) must be greater than Punch-{$i} ({$inTimeStr}).");
                            }

                            $inFormatted = $inCarbon->format('Y-m-d H:i:s');
                            $outFormatted = $outCarbon->format('Y-m-d H:i:s');

                            // Avoid double error reporting if already reported as duplicate above
                            if (!$isSequentialDuplicate) {
                                if (isset($seenPunchDateTimes[$inFormatted])) {
                                    $prevPunchNo = $seenPunchDateTimes[$inFormatted];
                                    $validator->errors()->add('punches', "Duplicate punch: Punch-{$i} has the same time ({$inTimeStr}) as Punch-{$prevPunchNo}.");
                                } else {
                                    $seenPunchDateTimes[$inFormatted] = $i;
                                }
                            }

                            if (!$isPairDuplicate) {
                                if (isset($seenPunchDateTimes[$outFormatted])) {
                                    $prevPunchNo = $seenPunchDateTimes[$outFormatted];
                                    $validator->errors()->add('punches', "Duplicate punch: Punch-" . ($i + 1) . " has the same time ({$outTimeStr}) as Punch-{$prevPunchNo}.");
                                } else {
                                    $seenPunchDateTimes[$outFormatted] = $i + 1;
                                }
                            }
                        }

                        if ($inCarbon && $outCarbon) {
                            if ($shiftBounds) {
                                [$shiftStart, $shiftEnd] = $shiftBounds;
                                if ($inCarbon->lt($shiftStart) || $inCarbon->gt($shiftEnd)) {
                                    $validator->errors()->add(
                                        'punches',
                                        "Punch-{$i} ({$inTimeStr}) is outside the assigned shift ({$shiftStart->format('Y-m-d H:i:s')} - {$shiftEnd->format('Y-m-d H:i:s')})."
                                    );
                                }
                                if ($outCarbon->lt($shiftStart) || $outCarbon->gt($shiftEnd)) {
                                    $validator->errors()->add(
                                        'punches',
                                        "Punch-" . ($i + 1) . " ({$outTimeStr}) is outside the assigned shift ({$shiftStart->format('Y-m-d H:i:s')} - {$shiftEnd->format('Y-m-d H:i:s')})."
                                    );
                                }
                            }

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
                    if ($shiftBounds && $employee && $employee->shift) {
                        [$shiftStart, $shiftEnd] = $shiftBounds;
                        $maxShiftMinutes = (int) $shiftStart->diffInMinutes($shiftEnd);

                        if ($holiday && $holiday->holiday_type === 'half day') {
                            $halfDayMaxMinutes = (int) ($maxShiftMinutes / 2);
                            if ($totalWorkedMinutes > $halfDayMaxMinutes) {
                                $halfHoursStr = sprintf('%02d:%02d', intdiv($halfDayMaxMinutes, 60), $halfDayMaxMinutes % 60);
                                $validator->errors()->add(
                                    'punches',
                                    "On a half-day holiday ({$holiday->holiday_title}), total worked duration cannot exceed {$halfHoursStr} hrs."
                                );
                            }
                        }

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
                    'errors_url'   => $this->generateImportErrorsUrl('employee-attendances.import.errors', $errorFile),
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
     * Get the employee's shift bounds for an attendance date.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function getShiftBounds(?Employee $employee, string $attendanceDate): ?array
    {
        if (
            !$employee
            || !$employee->shift
            || !$employee->shift->shift_start_time
            || !$employee->shift->shift_end_time
        ) {
            return null;
        }

        $shiftStartTime = Carbon::parse($employee->shift->shift_start_time)->format('H:i:s');
        $shiftEndTime = Carbon::parse($employee->shift->shift_end_time)->format('H:i:s');
        $shiftStart = Carbon::parse("{$attendanceDate} {$shiftStartTime}");
        $shiftEnd = Carbon::parse("{$attendanceDate} {$shiftEndTime}");

        if ($shiftEnd->lt($shiftStart)) {
            $shiftEnd->addDay();
        }

        return [$shiftStart, $shiftEnd];
    }

    /**
     * Check if a given date is a weekly off day for the employee.
     * Checks employee-specific weekly off, StoreWeeklyOff, and location weekly off.
     */
    private function isEmployeeWeeklyOff(Employee $employee, string $attendanceDate): bool
    {
        $dayOfWeek = strtolower(Carbon::parse($attendanceDate)->format('l'));

        // 1. Direct employee weekly off check
        if (!empty($employee->weekly_off)) {
            $weeklyOffDays = array_map('trim', explode(',', strtolower($employee->weekly_off)));
            if (in_array($dayOfWeek, $weeklyOffDays, true)) {
                return true;
            }
        }

        // 2. Store & Location weekly off check
        if ($employee->location_id) {
            $storeWeeklyOff = StoreWeeklyOff::where('location_id', $employee->location_id)
                ->where('is_active', true)
                ->where('is_delete', false)
                ->whereRaw('LOWER(weekly_off_day) = ?', [$dayOfWeek])
                ->exists();

            if ($storeWeeklyOff) {
                return true;
            }

            $location = $employee->relationLoaded('location') ? $employee->location : $employee->location()->first();
            if ($location && !empty($location->weekly_off_day)) {
                $locationDays = array_map('trim', explode(',', strtolower($location->weekly_off_day)));
                if (in_array($dayOfWeek, $locationDays, true)) {
                    return true;
                }
            }
        }

        return false;
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