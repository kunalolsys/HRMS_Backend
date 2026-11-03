<?php

namespace App\Exports;

use App\Models\PayrollRun;
use App\Models\Payslip;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class EsicContributionExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithColumnFormatting
{
    public function __construct(
        protected int $year,
        protected int $month,
        protected ?int $companyId = null
    ) {}

    /**
     * Fetch payslip records eligible for ESIC contribution.
     */
    public function collection(): Collection
    {
        $runsQuery = PayrollRun::query()
            ->where('payroll_year', $this->year)
            ->where('payroll_month', $this->month);

        if (!empty($this->companyId)) {
            $runsQuery->where('company_id', $this->companyId);
        }

        $runIds = $runsQuery->pluck('id');

        if ($runIds->isEmpty()) {
            return collect();
        }

        return Payslip::with(['employee:id,employee_name,esi_number,date_of_leave'])
            ->whereIn('payroll_run_id', $runIds)
            ->whereHas('employee', function ($query) {
                $query->whereNotNull('esi_number')
                      ->where('esi_number', '!=', '');
            })
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * ESIC Portal Standard 6-Column Header.
     */
    public function headings(): array
    {
        return [
            'IP Number',
            'IP Name',
            'No of Days for which wages paid',
            'Total Monthly Wages',
            'Reason Code for Zero workings days',
            'Last Working Day',
        ];
    }

    /**
     * Map each Payslip row to ESIC format.
     *
     * @param Payslip $payslip
     */
    public function map($payslip): array
    {
        $employee = $payslip->employee;
        $ipNumber = preg_replace('/\D/', '', (string) ($employee?->esi_number ?? ''));
        $ipName   = strtoupper(preg_replace('/[^a-zA-Z\s]/', '', (string) ($employee?->employee_name ?? '')));
        $paidDays = (float) $payslip->paid_days;
        $grossPay = round((float) $payslip->earned_gross, 2);

        $reasonCode     = 0;
        $lastWorkingDay = '';

        if ($paidDays <= 0) {
            $dateOfLeave = $employee?->date_of_leave ? Carbon::parse($employee->date_of_leave) : null;
            if ($dateOfLeave && $dateOfLeave->year === $this->year && $dateOfLeave->month === $this->month) {
                $reasonCode     = 1; // 1 = Left Service / Out of coverage
                $lastWorkingDay = $dateOfLeave->format('d/m/Y');
            } else {
                $reasonCode = 7; // 7 = Leave without pay / Non-working
            }
        }

        return [
            (string) $ipNumber,
            $ipName,
            $paidDays > 0 ? (int) round($paidDays) : 0,
            number_format($grossPay, 2, '.', ''),
            $reasonCode,
            $lastWorkingDay,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
            'C' => NumberFormat::FORMAT_NUMBER,
            'D' => NumberFormat::FORMAT_NUMBER_00,
            'E' => NumberFormat::FORMAT_NUMBER,
            'F' => NumberFormat::FORMAT_TEXT,
        ];
    }
}