<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\ShiftMaster;
use App\Models\EmployeeAttendance;
use App\Models\LateDeductionSlab;
use App\Models\ShortLeaveDeductionSlab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PayrollCalculationService
{
    /**
     * Cached Late Monthly Cumulative Deduction Slabs
     */
    private ?array $lateSlabs = null;

    /**
     * Cached Short Leave Monthly Cumulative Deduction Slabs
     */
    private ?array $shortLeaveSlabs = null;

    /**
     * Calculate Salary for an Employee for a given month/year based on Working Hours Attendance
     *
     * @param Employee $employee
     * @param int $year
     * @param int $month
     * @param iterable|null $attendanceLogs Pre-fetched logs for bulk processing
     * @return array
     */
    public function calculateMonthlySalary(Employee $employee, int $year, int $month, ?iterable $attendanceLogs = null): array
    {
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        $totalDaysInMonth = $startDate->daysInMonth;

        // Fetch Attendance Logs for the month if not preloaded
        if ($attendanceLogs === null) {
            $attendanceLogs = EmployeeAttendance::where('employee_id', $employee->id)
                ->whereBetween('attendance_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->get();
        }

        // Step 1: Process Daily Attendance & Classify Day Status based on Shift Working Hours
        $attendanceSummary = $this->processDailyAttendanceHours($employee, $attendanceLogs);

        // Step 2: Calculate Cumulative Slabs for Late & Short Leaves
        $lateDeductionDays = $this->calculateSlabDeduction($attendanceSummary['late_count'], $this->getLateSlabs());
        $shortLeaveDeductionDays = $this->calculateSlabDeduction($attendanceSummary['short_leave_count'], $this->getShortLeaveSlabs());

        // Step 3: Compute Total LOP (Loss of Pay) Days
        // LOP = Absence LOP + Unapproved Absence Penalties (1.5 days) + Late Slab Days + Short Leave Slab Days
        $totalLopDays = $attendanceSummary['raw_absent_lop']
            + $attendanceSummary['unapproved_penalty_days']
            + $lateDeductionDays
            + $shortLeaveDeductionDays;

        // Cap LOP to total days in month
        $totalLopDays = min($totalLopDays, $totalDaysInMonth);

        // Step 4: Calculate Paid Days
        $paidDays = max(0, $totalDaysInMonth - $totalLopDays);

        // Step 5: Pro-Rata Earnings Calculation (Base Salary from Pay Structure)
        $actualMonthlyGross = $employee->payStructure->actual_gross_salary;
        $actualBasic = $employee->payStructure->actual_basic;

        $perDayGrossRate = $actualMonthlyGross / $totalDaysInMonth;
        $earnedGross = round($perDayGrossRate * $paidDays, 2);

        $perDayBasicRate = $actualBasic / $totalDaysInMonth;
        $earnedBasic = round($perDayBasicRate * $paidDays, 2);

        // Pro-rate other earnings components
        $earnedHra = round(($employee->payStructure->actual_hra / $totalDaysInMonth) * $paidDays, 2);
        $earnedSpecialAllowance = round(($employee->payStructure->actual_special_allowance / $totalDaysInMonth) * $paidDays, 2);

        // Step 6: Statutory Deductions (PF, ESI, LWF)
        $pfDeduction = 0.00;
        if ($employee->is_pf_applicable && $employee->tenure_months >= 9 && $earnedBasic <= 15000) {
            $pfDeduction = round($earnedBasic * 0.12, 2); // 12% EE PF
        }

        $esiDeduction = 0.00;
        if ($employee->is_esi_applicable && $earnedGross <= 21000) {
            $esiDeduction = round($earnedGross * 0.0075, 2); // 0.75% EE ESI
        }

        $lwfDeduction = 0.00;
        if ($employee->store->state_code === 'HR' && $employee->is_lwf_applicable) {
            $lwfDeduction = 5.00; // Haryana LWF Flat EE share
        }

        $totalStatutoryDeductions = $pfDeduction + $esiDeduction + $lwfDeduction;

        // Step 7: Apply 50% Overall Deduction Cap (Code on Wages Rule)
        $maxAllowedDeduction = round($earnedGross * 0.50, 2);
        $appliedDeductions = min($totalStatutoryDeductions, $maxAllowedDeduction);

        // Step 8: Net Payable Salary
        $netPay = round($earnedGross - $appliedDeductions, 2);

        return [
            'employee_code'       => $employee->employee_code,
            'month_year'          => $startDate->format('M Y'),
            'total_days_in_month' => $totalDaysInMonth,
            'attendance_breakdown' => [
                'full_days'                => $attendanceSummary['full_day_count'],
                'half_days'                => $attendanceSummary['half_day_count'],
                'short_leaves'             => $attendanceSummary['short_leave_count'],
                'late_count'               => $attendanceSummary['late_count'],
                'approved_unpaid_absent'  => $attendanceSummary['approved_absent_count'],
                'unapproved_absent_days'  => $attendanceSummary['unapproved_absent_count'],
            ],
            'deduction_days_summary' => [
                'raw_absent_lop_days'      => $attendanceSummary['raw_absent_lop'],
                'unapproved_penalty_days'  => $attendanceSummary['unapproved_penalty_days'],
                'late_slab_deduction'      => $lateDeductionDays,
                'short_leave_slab_deduct'  => $shortLeaveDeductionDays,
                'total_lop_days'           => $totalLopDays,
                'final_paid_days'          => $paidDays,
            ],
            'salary_breakdown' => [
                'actual_gross'             => $actualMonthlyGross,
                'earned_basic'             => $earnedBasic,
                'earned_hra'               => $earnedHra,
                'earned_special_allowance' => $earnedSpecialAllowance,
                'earned_gross'             => $earnedGross,
                'pf_deduction'             => $pfDeduction,
                'esi_deduction'            => $esiDeduction,
                'lwf_deduction'            => $lwfDeduction,
                'total_deductions'         => $appliedDeductions,
                'net_payable'              => $netPay,
            ]
        ];
    }

    /**
     * Process Daily Working Hours & determine Day Status based on Shift Bands
     */
    private function processDailyAttendanceHours(Employee $employee, $attendanceLogs): array
    {
        $shift = $employee->assignedShift; // e.g. Sales_Boys (11 hrs total)

        $fullDayCount = 0;
        $halfDayCount = 0;
        $shortLeaveCount = 0;
        $lateCount = 0;
        $approvedAbsentCount = 0;
        $unapprovedAbsentCount = 0;

        $rawAbsentLopDays = 0.0;
        $unapprovedPenaltyDays = 0.0;

        foreach ($attendanceLogs as $log) {
            // Status Override check (Weekly Off, Holiday, Official Tour)
            if (!empty($log->status_override)) {
                if (in_array($log->status_override, ['WEEKLY_OFF', 'HOLIDAY', 'OFFICIAL_TOUR'])) {
                    $fullDayCount++; // Paid Day
                    continue;
                }
            }

            // Calculate Worked Hours from In/Out Time or direct hours
            $hoursWorked = $log->hours_worked;
            if (is_null($hoursWorked) && $log->in_time && $log->out_time) {
                $in = Carbon::parse($log->in_time);
                $out = Carbon::parse($log->out_time);
                $hoursWorked = round($out->diffInMinutes($in) / 60.0, 2);
            }

            // Late Check (Starts after 20 minutes grace period)
            $isLate = false;
            if ($log->in_time) {
                $shiftStart = Carbon::parse($log->attendance_date . ' ' . $shift->start_time);
                $actualIn = Carbon::parse($log->attendance_date . ' ' . $log->in_time);
                if ($actualIn->gt($shiftStart->copy()->addMinutes(20))) {
                    $isLate = true;
                }
            }

            // Evaluate Working Hours against Shift Thresholds
            // Example Sales_Boys: Full >= 10.5, Short >= 8.93 (08:56), Half >= 5.51 (05:31), Absent <= 5.5
            if ($hoursWorked >= $shift->full_day_hours) {
                $fullDayCount++;
                if ($isLate) {
                    $lateCount++;
                }
            } elseif ($hoursWorked >= $shift->short_leave_hours) {
                // Short Leave Rule: If late AND short leave, counted ONLY as short leave, NOT late
                $shortLeaveCount++;
            } elseif ($hoursWorked >= $shift->half_day_hours) {
                $halfDayCount++;
                $rawAbsentLopDays += 0.5; // Half day absent
                if ($isLate) {
                    $lateCount++;
                }
            } else {
                // Absent (< half day hours)
                if ($log->is_approved_absence) {
                    $approvedAbsentCount++;
                    $rawAbsentLopDays += 1.0; // 1 Day LOP
                } else {
                    $unapprovedAbsentCount++;
                    $rawAbsentLopDays += 1.0; // 1 Day LOP
                    $unapprovedPenaltyDays += 0.5; // 1.5-day total penalty rule (1 LOP + 0.5 Penalty)
                }
            }
        }

        return [
            'full_day_count'           => $fullDayCount,
            'half_day_count'           => $halfDayCount,
            'short_leave_count'        => $shortLeaveCount,
            'late_count'               => $lateCount,
            'approved_absent_count'    => $approvedAbsentCount,
            'unapproved_absent_count'  => $unapprovedAbsentCount,
            'raw_absent_lop'           => $rawAbsentLopDays,
            'unapproved_penalty_days'  => $unapprovedPenaltyDays,
        ];
    }

    /**
     * Compute Cumulative Slab Deduction Days
     */
    private function calculateSlabDeduction(int $count, array $slabMap): float
    {
        $deductedDays = 0.0;
        foreach ($slabMap as $threshold => $days) {
            if ($count >= $threshold) {
                $deductedDays = $days;
            } else {
                break;
            }
        }
        return $deductedDays;
    }

    /**
     * Fetch and cache active Late Deduction Slabs from database
     *
     * @return array<int, float>
     */
    private function getLateSlabs(): array
    {
        if ($this->lateSlabs === null) {
            $this->lateSlabs = LateDeductionSlab::where('is_active', true)
                ->where('is_delete', false)
                ->orderBy('lates_count', 'asc')
                ->pluck('days_deducted', 'lates_count')
                ->toArray();
        }

        return $this->lateSlabs;
    }

    /**
     * Fetch and cache active Short Leave Deduction Slabs from database
     *
     * @return array<int, float>
     */
    private function getShortLeaveSlabs(): array
    {
        if ($this->shortLeaveSlabs === null) {
            $this->shortLeaveSlabs = ShortLeaveDeductionSlab::where('is_active', true)
                ->where('is_delete', false)
                ->orderBy('short_leaves_count', 'asc')
                ->pluck('days_deducted', 'short_leaves_count')
                ->toArray();
        }

        return $this->shortLeaveSlabs;
    }
}
