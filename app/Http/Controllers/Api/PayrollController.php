<?php

namespace App\Http\Controllers\Api;

use App\Exports\EsicContributionExport;
use App\Http\Controllers\Controller;
use App\Imports\AttendanceExcelImport;
use App\Imports\InterimCsvImport;
use App\Jobs\Payroll\ProcessPayrollRunJob;
use App\Models\Employee;
use App\Models\Company;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PayrollController extends Controller
{
    public function __construct(
        protected PayrollCalculationService $payrollService
    ) {}

    /**
     * Attendance Excel Upload Handler.
     * HO Consolidated Excel Workbook upload for Attendance Logs.
     */
    public function uploadAttendanceExcel(Request $request): JsonResponse
    {
        $request->validate([
            'payroll_month' => 'required|date_format:Y-m',
            'company_id'    => 'required|exists:companies,id',
            'file'          => 'required|mimes:xlsx,xls,csv|max:20480', // 20MB Max
        ]);

        $monthStr = $request->input('payroll_month');
        $companyId = (int) $request->input('company_id');
        $date = Carbon::createFromFormat('Y-m', $monthStr)->startOfMonth();

        // Check if payroll period is already locked
        if ($this->isPeriodLocked($companyId, $date->year, $date->month)) {
            return response()->json([
                'success' => false,
                'message' => "Payroll period {$monthStr} is LOCKED. Attendance re-upload is prohibited.",
            ], 422);
        }

        DB::beginTransaction();
        try {
            $import = new AttendanceExcelImport($companyId, $date->year, $date->month, auth()->id());
            Excel::import($import, $request->file('file'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Attendance Excel imported successfully for {$monthStr}.",
                'stats'   => [
                    'total_rows_processed' => method_exists($import, 'getProcessedCount') ? $import->getProcessedCount() : null,
                    'errors'               => method_exists($import, 'getErrors') ? $import->getErrors() : [],
                ],
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Attendance Excel Upload Failed: {$e->getMessage()}", ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'message' => "Import Error: {$e->getMessage()}",
            ], 500);
        }
    }

    /**
     * Interim CSV Upload Handler.
     * Ingests incentives, loans/advances, fines, and arrears templates.
     */
    public function uploadInterimCsv(Request $request): JsonResponse
    {
        $request->validate([
            'template_type' => 'required|in:incentive,loan_installment,fines,arrears',
            'payroll_month' => 'required|date_format:Y-m',
            'file'          => 'required|mimes:csv,txt|max:10240',
        ]);

        $templateType = $request->input('template_type');
        $monthStr = $request->input('payroll_month');

        try {
            $import = new InterimCsvImport($templateType, $monthStr, auth()->id());
            Excel::import($import, $request->file('file'));

            return response()->json([
                'success' => true,
                'message' => "CSV Upload for '{$templateType}' completed successfully for {$monthStr}.",
            ]);
        } catch (Throwable $e) {
            Log::error("Interim CSV Upload Failed: {$e->getMessage()}", ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'message' => "CSV Upload Error: {$e->getMessage()}",
            ], 500);
        }
    }

    /**
     * Monthly Payroll Run Engine Execution.
     * Queues salary computation for all active eligible employees.
     */
    public function runPayroll(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'nullable|integer|exists:companies,id',
            'year'       => 'required|integer|min:2020|max:2099',
            'month'      => 'required|integer|min:1|max:12',
        ], [
            'company_id.exists' => 'The selected company does not exist.',
        ]);

        $companyId = $request->filled('company_id') ? (int) $request->input('company_id') : null;
        $year      = (int) $request->input('year');
        $month     = (int) $request->input('month');
        $userId    = auth()->id();

        // 1. Agar specific company_id di gayi ho
        if ($companyId) {
            $company = Company::where('is_active', true)
                ->where('is_delete', false)
                ->find($companyId);

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected company is either inactive or deleted.',
                ], 422);
            }

            if ($this->isPeriodLocked($companyId, $year, $month)) {
                $monthPad = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
                return response()->json([
                    'success' => false,
                    'message' => "Payroll for {$company->company_name} ({$year}-{$monthPad}) is already locked. Cannot re-run.",
                ], 422);
            }

            try {
                $payrollRun = PayrollRun::updateOrCreate(
                    [
                        'company_id'    => $companyId,
                        'payroll_year'  => $year,
                        'payroll_month' => $month,
                    ],
                    [
                        'status'        => 'QUEUED',
                        'run_by_user'   => $userId,
                        'run_timestamp' => now(),
                    ]
                );

                ProcessPayrollRunJob::dispatch($payrollRun);

                return response()->json([
                    'success' => true,
                    'message' => "Payroll calculation for {$company->company_name} queued successfully in the background.",
                    'data'    => [
                        'payroll_run_id' => $payrollRun->id,
                        'company_id'     => $company->id,
                        'company_name'   => $company->company_name,
                        'payroll_year'   => $year,
                        'payroll_month'  => $month,
                        'status'         => 'QUEUED',
                    ],
                ], 202);
            } catch (Throwable $e) {
                Log::error("Payroll Dispatch Error for Company {$companyId}: {$e->getMessage()}", ['trace' => $e->getTraceAsString()]);

                return response()->json([
                    'success' => false,
                    'message' => "Payroll Execution Failed: {$e->getMessage()}",
                ], 500);
            }
        }

        // 2. Agar company_id na bheji ho to sabhi active companies ke liye chalana
        $companies = Company::where('is_active', true)
            ->where('is_delete', false)
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No active companies found in the system.',
            ], 422);
        }

        $dispatched = [];
        $skippedLocked = [];

        foreach ($companies as $company) {
            if ($this->isPeriodLocked($company->id, $year, $month)) {
                $skippedLocked[] = [
                    'company_id'   => $company->id,
                    'company_name' => $company->company_name,
                    'reason'       => 'Period is locked',
                ];
                continue;
            }

            try {
                $payrollRun = PayrollRun::updateOrCreate(
                    [
                        'company_id'    => $company->id,
                        'payroll_year'  => $year,
                        'payroll_month' => $month,
                    ],
                    [
                        'status'        => 'QUEUED',
                        'run_by_user'   => $userId,
                        'run_timestamp' => now(),
                    ]
                );

                ProcessPayrollRunJob::dispatch($payrollRun);

                $dispatched[] = [
                    'payroll_run_id' => $payrollRun->id,
                    'company_id'     => $company->id,
                    'company_name'   => $company->company_name,
                    'status'         => 'QUEUED',
                ];
            } catch (Throwable $e) {
                Log::error("Bulk Payroll Dispatch Error for Company {$company->id}: {$e->getMessage()}");
            }
        }

        if (empty($dispatched) && !empty($skippedLocked)) {
            return response()->json([
                'success'        => false,
                'message'        => 'All active companies have already locked payroll for this period.',
                'skipped_locked' => $skippedLocked,
            ], 422);
        }

        return response()->json([
            'success'        => true,
            'message'        => 'Payroll calculation queued successfully for ' . count($dispatched) . ' company/companies.',
            'payroll_year'   => $year,
            'payroll_month'  => $month,
            'dispatched'     => $dispatched,
            'skipped_locked' => $skippedLocked,
        ], 202);
    }

    /**
     * Track status and progress of payroll runs (bulk or single).
     */
    public function getPayrollStatus(Request $request): JsonResponse
    {
        $request->validate([
            'run_ids'    => 'nullable|string',
            'company_id' => 'nullable|integer|exists:companies,id',
            'year'       => 'nullable|integer|min:2020|max:2099',
            'month'      => 'nullable|integer|min:1|max:12',
        ]);

        $runIdsParam = $request->input('run_ids');
        $companyId   = $request->filled('company_id') ? (int) $request->input('company_id') : null;
        $year        = $request->filled('year') ? (int) $request->input('year') : null;
        $month       = $request->filled('month') ? (int) $request->input('month') : null;

        if (empty($runIdsParam) && (empty($year) || empty($month))) {
            return response()->json([
                'success' => false,
                'message' => 'Either provide "run_ids" or both "year" and "month" to check payroll status.',
            ], 422);
        }

        $query = PayrollRun::with([
            'company:id,company_code,company_name',
            'runByUser:id,name',
            'lockedByUser:id,name',
        ])->withCount('payslips');

        if (!empty($runIdsParam)) {
            $runIds = array_filter(array_map('intval', explode(',', $runIdsParam)));
            $query->whereIn('id', $runIds);
        } else {
            $query->where('payroll_year', $year)
                  ->where('payroll_month', $month);

            if ($companyId) {
                $query->where('company_id', $companyId);
            }
        }

        $runs = $query->orderByDesc('id')->get();

        if ($runs->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No payroll runs found matching the criteria.',
            ], 404);
        }

        $runsData = $runs->map(function ($run) {
            $totalEmployees = (int) $run->total_employees;
            $processedCount = (int) $run->payslips_count;

            if (in_array($run->status, ['QUEUED', 'PROCESSING'], true) && $totalEmployees === 0 && $run->company_id) {
                $totalEmployees = Employee::where('company_id', $run->company_id)
                    ->where('is_active', true)
                    ->where('is_delete', false)
                    ->count();
            }

            $progressPercentage = 0;
            if ($run->status === 'COMPLETED' || $run->status === 'LOCKED') {
                $progressPercentage = 100;
            } elseif ($totalEmployees > 0) {
                $progressPercentage = min(100, round(($processedCount / $totalEmployees) * 100, 2));
            }

            return [
                'payroll_run_id'      => $run->id,
                'company_id'          => $run->company_id,
                'company_code'        => $run->company?->company_code,
                'company_name'        => $run->company?->company_name ?? 'Consolidated',
                'payroll_year'        => $run->payroll_year,
                'payroll_month'       => $run->payroll_month,
                'status'              => $run->status,
                'progress_percentage' => $progressPercentage,
                'processed_employees' => $processedCount,
                'total_employees'     => $totalEmployees,
                'total_gross_paid'    => (float) $run->total_gross_paid,
                'total_net_paid'      => (float) $run->total_net_paid,
                'run_timestamp'       => $run->run_timestamp?->toDateTimeString(),
                'run_by'              => $run->runByUser?->name,
                'locked_at'           => $run->locked_at?->toDateTimeString(),
                'locked_by'           => $run->lockedByUser?->name,
            ];
        });

        $summary = [
            'total_runs' => $runsData->count(),
            'queued'     => $runsData->where('status', 'QUEUED')->count(),
            'processing' => $runsData->where('status', 'PROCESSING')->count(),
            'completed'  => $runsData->where('status', 'COMPLETED')->count(),
            'locked'     => $runsData->where('status', 'LOCKED')->count(),
            'failed'     => $runsData->where('status', 'FAILED')->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Payroll run status retrieved successfully.',
            'summary' => $summary,
            'data'    => $runsData,
        ], 200);
    }

    /**
     * Lock Payroll Period (Maker-Checker Control).
     */
    public function lockPayrollPeriod(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'year'       => 'required|integer',
            'month'      => 'required|integer',
        ]);

        $payrollRun = PayrollRun::where('company_id', $request->company_id)
            ->where('payroll_year', $request->year)
            ->where('payroll_month', $request->month)
            ->firstOrFail();

        $payrollRun->update([
            'status'    => 'LOCKED',
            'locked_by' => auth()->id(),
            'locked_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Payroll period {$request->year}-{$request->month} is now LOCKED.",
        ]);
    }

    /**
     * Download Monthly Statutory Challan Summary (PF & ESI) as CSV for Accounting.
     */
    public function downloadStatutoryChallanSummary(Request $request): StreamedResponse|JsonResponse
    {
        $request->validate([
            'company_id' => 'nullable|exists:companies,id',
            'year'       => 'required|integer|min:2020|max:2099',
            'month'      => 'required|integer|min:1|max:12',
        ]);

        $companyId = $request->input('company_id');
        $year      = (int) $request->input('year');
        $month     = (int) $request->input('month');

        $runsQuery = PayrollRun::with('company')
            ->where('payroll_year', $year)
            ->where('payroll_month', $month);

        if (!empty($companyId)) {
            $runsQuery->where('company_id', $companyId);
        }

        $runs = $runsQuery->get();

        if ($runs->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => "No payroll run found for {$year}-" . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.',
            ], 404);
        }

        $payslips = Payslip::with(['employee:id,employee_code,employee_name,uan_number,pf_number,esi_number'])
            ->whereIn('payroll_run_id', $runs->pluck('id'))
            ->orderBy('id', 'asc')
            ->get();

        if ($payslips->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No payslip records found for the selected payroll period.',
            ], 404);
        }

        $companyNames    = $runs->map(fn ($r) => $r->company?->company_name ?? 'Consolidated')->unique()->implode(', ');
        $periodFormatted = Carbon::createFromDate($year, $month, 1)->format('F Y');
        $filename        = 'statutory_challan_summary_' . $year . '_' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.csv';

        // Precompute Aggregates
        $totalGross           = (float) $payslips->sum('earned_gross');
        $totalBasic           = (float) $payslips->sum('earned_basic');
        $totalPfEe            = (float) $payslips->sum('pf_employee_deduction');
        $totalEpsEr           = (float) $payslips->sum('eps_employer_contribution');
        $totalPfEr            = (float) $payslips->sum('pf_employer_contribution');
        $totalPfRemittance    = round($totalPfEe + $totalEpsEr + $totalPfEr, 2);
        $totalEsiEe           = (float) $payslips->sum('esi_employee_deduction');
        $totalEsiEr           = (float) $payslips->sum('esi_employer_contribution');
        $totalEsiRemittance   = round($totalEsiEe + $totalEsiEr, 2);
        $grandTotalRemittance = round($totalPfRemittance + $totalEsiRemittance, 2);

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use (
            $companyNames,
            $periodFormatted,
            $totalGross,
            $totalBasic,
            $totalPfEe,
            $totalEpsEr,
            $totalPfEr,
            $totalPfRemittance,
            $totalEsiEe,
            $totalEsiEr,
            $totalEsiRemittance,
            $grandTotalRemittance,
            $payslips
        ) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Microsoft Excel

            // Header Information
            fputcsv($handle, ['MONTHLY STATUTORY CHALLAN SUMMARY (PF & ESI)']);
            fputcsv($handle, ['Company / Entity', $companyNames]);
            fputcsv($handle, ['Payroll Period', $periodFormatted]);
            fputcsv($handle, ['Generated On', now()->format('Y-m-d H:i:s')]);
            fputcsv($handle, []);

            // Accounting Remittance Summary Block
            fputcsv($handle, ['ACCOUNTING CHALLAN SUMMARY', 'AMOUNT (INR)']);
            fputcsv($handle, ['Total Gross Earnings', number_format($totalGross, 2, '.', '')]);
            fputcsv($handle, ['Total Basic (PF Eligible Wages)', number_format($totalBasic, 2, '.', '')]);
            fputcsv($handle, ['EPF Employee Contribution (12% - A/C 01)', number_format($totalPfEe, 2, '.', '')]);
            fputcsv($handle, ['EPF Employer Contribution (3.67% - A/C 01)', number_format($totalPfEr, 2, '.', '')]);
            fputcsv($handle, ['EPS Employer Pension (8.33% - A/C 10)', number_format($totalEpsEr, 2, '.', '')]);
            fputcsv($handle, ['Total PF Remittance Payable', number_format($totalPfRemittance, 2, '.', '')]);
            fputcsv($handle, ['ESI Employee Contribution (0.75%)', number_format($totalEsiEe, 2, '.', '')]);
            fputcsv($handle, ['ESI Employer Contribution (3.25%)', number_format($totalEsiEr, 2, '.', '')]);
            fputcsv($handle, ['Total ESI Remittance Payable', number_format($totalEsiRemittance, 2, '.', '')]);
            fputcsv($handle, ['GRAND TOTAL STATUTORY REMITTANCE (PF + ESI)', number_format($grandTotalRemittance, 2, '.', '')]);
            fputcsv($handle, []);

            // Detailed Employee Schedule
            fputcsv($handle, [
                'Employee Code', 'Employee Name', 'UAN', 'PF Number', 'ESI Number',
                'Paid Days', 'Earned Gross', 'Earned Basic', 'PF EE (12%)',
                'EPS ER (8.33%)', 'EPF ER (3.67%)', 'Total PF ER', 'Total PF Payable',
                'ESI EE (0.75%)', 'ESI ER (3.25%)', 'Total ESI Payable', 'Total Statutory Remittance',
            ]);

            foreach ($payslips as $payslip) {
                $employee = $payslip->employee;
                $pfEe     = (float) $payslip->pf_employee_deduction;
                $epsEr    = (float) $payslip->eps_employer_contribution;
                $pfEr     = (float) $payslip->pf_employer_contribution;
                $totalPf  = round($pfEe + $epsEr + $pfEr, 2);
                $esiEe    = (float) $payslip->esi_employee_deduction;
                $esiEr    = (float) $payslip->esi_employer_contribution;
                $totalEsi = round($esiEe + $esiEr, 2);

                fputcsv($handle, [
                    $employee?->employee_code ?? 'N/A',
                    $employee?->employee_name ?? 'N/A',
                    $employee?->uan_number ?? '',
                    $employee?->pf_number ?? '',
                    $employee?->esi_number ?? '',
                    number_format((float) $payslip->paid_days, 2, '.', ''),
                    number_format((float) $payslip->earned_gross, 2, '.', ''),
                    number_format((float) $payslip->earned_basic, 2, '.', ''),
                    number_format($pfEe, 2, '.', ''),
                    number_format($epsEr, 2, '.', ''),
                    number_format($pfEr, 2, '.', ''),
                    number_format(round($epsEr + $pfEr, 2), 2, '.', ''),
                    number_format($totalPf, 2, '.', ''),
                    number_format($esiEe, 2, '.', ''),
                    number_format($esiEr, 2, '.', ''),
                    number_format($totalEsi, 2, '.', ''),
                    number_format(round($totalPf + $totalEsi, 2), 2, '.', ''),
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Download Monthly ESIC Contribution Excel file for portal upload.
     */
    public function downloadEsicContribution(Request $request): BinaryFileResponse|JsonResponse
    {
        $request->validate([
            'company_id' => 'nullable|exists:companies,id',
            'year'       => 'required|integer|min:2020|max:2099',
            'month'      => 'required|integer|min:1|max:12',
        ]);

        $companyId = $request->input('company_id');
        $year      = (int) $request->input('year');
        $month     = (int) $request->input('month');

        $runsQuery = PayrollRun::where('payroll_year', $year)
            ->where('payroll_month', $month);

        if (!empty($companyId)) {
            $runsQuery->where('company_id', $companyId);
        }

        $runIds = $runsQuery->pluck('id');

        if ($runIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => "No payroll run found for {$year}-" . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.',
            ], 404);
        }

        $hasEligiblePayslips = Payslip::whereIn('payroll_run_id', $runIds)
            ->whereHas('employee', fn ($q) => $q->whereNotNull('esi_number')->where('esi_number', '!=', ''))
            ->exists();

        if (!$hasEligiblePayslips) {
            return response()->json([
                'success' => false,
                'message' => 'No eligible employees with an ESIC number found for this payroll period.',
            ], 404);
        }

        $filename = 'ESIC_Monthly_Contribution_' . $year . '_' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.xlsx';

        return Excel::download(new EsicContributionExport($year, $month, $companyId), $filename);
    }

    /**
     * Check if a payroll period has been finalized and locked.
     */
    private function isPeriodLocked(?int $companyId, int $year, int $month): bool
    {
        $query = PayrollRun::where('payroll_year', $year)
            ->where('payroll_month', $month)
            ->where('status', 'LOCKED');

        if (!empty($companyId)) {
            $query->where('company_id', $companyId);
        }

        return $query->exists();
    }
}