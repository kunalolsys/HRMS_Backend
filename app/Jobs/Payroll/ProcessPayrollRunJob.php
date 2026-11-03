<?php

namespace App\Jobs\Payroll;

use App\Models\EmployeeAttendance;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\StatutoryParameterMaster;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPayrollRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes execution limit for large employee counts
    public int $tries = 1;

    public function __construct(
        protected PayrollRun $payrollRun
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PayrollCalculationService $payrollService): void
    {
        $this->payrollRun->update(['status' => 'PROCESSING']);

        $companyId = $this->payrollRun->company_id;
        $year = (int) $this->payrollRun->payroll_year;
        $month = (int) $this->payrollRun->payroll_month;

        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth()->format('Y-m-d');
        $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth()->format('Y-m-d');
        $statutoryRule = StatutoryParameterMaster::getEffectiveRule($startDate);

        $processedCount = 0;
        $totalGross = 0.0;
        $totalNet = 0.0;

        $query = Employee::where('is_active', true)
            ->with(['payStructure', 'assignedShift', 'store']);

        if (!empty($companyId)) {
            $query->where('company_id', $companyId);
        }

        // Process in memory-efficient chunks of 100
        $query->chunkById(100, function ($employees) use ($payrollService, $year, $month, $startDate, $endDate, $statutoryRule, &$processedCount, &$totalGross, &$totalNet) {
            $employeeIds = $employees->pluck('id')->all();

            // Bulk pre-fetch attendance logs for the entire chunk
            $chunkAttendanceLogs = EmployeeAttendance::whereIn('employee_id', $employeeIds)
                ->whereBetween('attendance_date', [$startDate, $endDate])
                ->get()
                ->groupBy('employee_id');

            $payslipRecords = [];

            foreach ($employees as $employee) {
                try {
                    $logs = $chunkAttendanceLogs->get($employee->id, collect());
                    $result = $payrollService->calculateMonthlySalary($employee, $year, $month, $logs);

                    $earnedGross = $result['salary_breakdown']['earned_gross'];
                    $earnedBasic = $result['salary_breakdown']['earned_basic'];
                    $pfEmployeeDeduction = $result['salary_breakdown']['pf_deduction'] ?? 0;
                    $esiEmployeeDeduction = $result['salary_breakdown']['esi_deduction'] ?? 0;

                    $tempPayslip = new Payslip([
                        'earned_gross'           => $earnedGross,
                        'earned_basic'           => $earnedBasic,
                        'pf_employee_deduction'  => $pfEmployeeDeduction,
                        'esi_employee_deduction' => $esiEmployeeDeduction,
                    ]);
                    $employerContributions = $tempPayslip->getEmployerContributions($statutoryRule);

                    $payslipRecords[] = [
                        'payroll_run_id'              => $this->payrollRun->id,
                        'employee_id'                 => $employee->id,
                        'paid_days'                   => $result['deduction_days_summary']['final_paid_days'],
                        'earned_gross'                => $earnedGross,
                        'earned_basic'                => $earnedBasic,
                        'pf_employee_deduction'       => $pfEmployeeDeduction,
                        'esi_employee_deduction'      => $esiEmployeeDeduction,
                        'total_deductions'            => $result['salary_breakdown']['total_deductions'] ?? 0,
                        'pf_employer_contribution'    => $employerContributions['pf_employer'],
                        'eps_employer_contribution'   => $employerContributions['eps_employer'],
                        'esi_employer_contribution'   => $employerContributions['esi_employer'],
                        'total_employer_contribution' => $employerContributions['total_contribution'],
                        'net_pay'                     => $result['salary_breakdown']['net_payable'],
                        'status'                      => 'DRAFT',
                        'created_at'                  => now(),
                        'updated_at'                  => now(),
                    ];

                    $totalGross += $earnedGross;
                    $totalNet   += $result['salary_breakdown']['net_payable'];
                    $processedCount++;
                } catch (Throwable $e) {
                    Log::error("Failed payroll calculation for employee ID {$employee->id}: " . $e->getMessage());
                }
            }

            // Batch insert/update payslips in a short transaction
            if (!empty($payslipRecords)) {
                DB::transaction(function () use ($payslipRecords) {
                    Payslip::upsert(
                        $payslipRecords,
                        ['payroll_run_id', 'employee_id'],
                        [
                            'paid_days',
                            'earned_gross',
                            'earned_basic',
                            'pf_employee_deduction',
                            'esi_employee_deduction',
                            'total_deductions',
                            'pf_employer_contribution',
                            'eps_employer_contribution',
                            'esi_employer_contribution',
                            'total_employer_contribution',
                            'net_pay',
                            'status',
                            'updated_at',
                        ]
                    );
                });
            }
        });

        // Finalize Payroll Run summary
        $this->payrollRun->update([
            'status'           => 'COMPLETED',
            'total_employees'  => $processedCount,
            'total_gross_paid' => round($totalGross, 2),
            'total_net_paid'   => round($totalNet, 2),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("Payroll Run ID {$this->payrollRun->id} failed: " . $exception->getMessage(), [
            'trace' => $exception->getTraceAsString()
        ]);

        $this->payrollRun->update(['status' => 'FAILED']);
    }
}