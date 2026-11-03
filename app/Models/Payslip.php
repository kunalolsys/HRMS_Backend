<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    use HasFactory;

    protected $table = 'payslips';

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
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
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_days'                   => 'decimal:2',
            'earned_gross'                => 'decimal:2',
            'earned_basic'                => 'decimal:2',
            'pf_employee_deduction'       => 'decimal:2',
            'esi_employee_deduction'      => 'decimal:2',
            'total_deductions'            => 'decimal:2',
            'pf_employer_contribution'    => 'decimal:2',
            'eps_employer_contribution'   => 'decimal:2',
            'esi_employer_contribution'   => 'decimal:2',
            'total_employer_contribution' => 'decimal:2',
            'net_pay'                     => 'decimal:2',
        ];
    }

    /**
     * Payroll run this payslip belongs to.
     */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * Employee for whom this payslip was generated.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Resolve effective statutory parameters rule for this payslip.
     */
    public function resolveStatutoryRule(?StatutoryParameterMaster $rule = null): ?StatutoryParameterMaster
    {
        if ($rule !== null) {
            return $rule;
        }

        $date = null;
        if ($this->relationLoaded('payrollRun') && $this->payrollRun) {
            $date = Carbon::createFromDate($this->payrollRun->payroll_year, $this->payrollRun->payroll_month, 1)->toDateString();
        } elseif ($this->payroll_run_id) {
            $run = $this->payrollRun()->first(['payroll_year', 'payroll_month']);
            if ($run) {
                $date = Carbon::createFromDate($run->payroll_year, $run->payroll_month, 1)->toDateString();
            }
        }

        return StatutoryParameterMaster::getEffectiveRule($date);
    }

    /**
     * Compute total Employer PF contribution (12% of capped/earned basic).
     */
    public function calculateTotalPfEmployerContribution(?StatutoryParameterMaster $rule = null): float
    {
        if ((float) $this->pf_employee_deduction <= 0) {
            return 0.00;
        }

        $rule = $this->resolveStatutoryRule($rule);
        $rate = $rule?->pf_employer_rate !== null ? (float) $rule->pf_employer_rate : 12.00;
        $ceiling = $rule?->pf_wage_ceiling !== null ? (float) $rule->pf_wage_ceiling : 15000.00;
        $baseWage = min((float) $this->earned_basic, $ceiling);

        return round($baseWage * ($rate / 100), 2);
    }

    /**
     * Compute Employer EPS contribution (8.33% capped at max monthly cap, e.g., Rs. 1,250).
     */
    public function calculateEpsEmployerContribution(?StatutoryParameterMaster $rule = null): float
    {
        if ((float) $this->pf_employee_deduction <= 0) {
            return 0.00;
        }

        $rule = $this->resolveStatutoryRule($rule);
        $rate = $rule?->pf_eps_rate !== null ? (float) $rule->pf_eps_rate : 8.33;
        $ceiling = $rule?->pf_wage_ceiling !== null ? (float) $rule->pf_wage_ceiling : 15000.00;
        $maxCap = $rule?->pf_eps_max_cap !== null ? (float) $rule->pf_eps_max_cap : 1250.00;

        $baseWage = min((float) $this->earned_basic, $ceiling);
        $eps = round($baseWage * ($rate / 100), 2);

        return min($eps, $maxCap);
    }

    /**
     * Compute Net Employer PF (EPF 3.67% share = Total 12% - EPS share).
     */
    public function calculatePfEmployerContribution(?StatutoryParameterMaster $rule = null): float
    {
        $totalPf = $this->calculateTotalPfEmployerContribution($rule);
        $eps = $this->calculateEpsEmployerContribution($rule);

        return max(0.00, round($totalPf - $eps, 2));
    }

    /**
     * Compute Employer ESI contribution (3.25% of earned gross).
     */
    public function calculateEsiEmployerContribution(?StatutoryParameterMaster $rule = null): float
    {
        if ((float) $this->esi_employee_deduction <= 0) {
            return 0.00;
        }

        $rule = $this->resolveStatutoryRule($rule);
        $rate = $rule?->esi_employer_rate !== null ? (float) $rule->esi_employer_rate : 3.25;

        return round((float) $this->earned_gross * ($rate / 100), 2);
    }

    /**
     * Get aggregated breakdown of all employer statutory contributions.
     *
     * @return array{eps_employer: float, pf_employer: float, total_pf_employer: float, esi_employer: float, total_contribution: float}
     */
    public function getEmployerContributions(?StatutoryParameterMaster $rule = null): array
    {
        $rule = $this->resolveStatutoryRule($rule);

        return [
            'eps_employer'       => $this->calculateEpsEmployerContribution($rule),
            'pf_employer'        => $this->calculatePfEmployerContribution($rule),
            'total_pf_employer'  => $this->calculateTotalPfEmployerContribution($rule),
            'esi_employer'       => $this->calculateEsiEmployerContribution($rule),
            'total_contribution' => round($this->calculateTotalPfEmployerContribution($rule) + $this->calculateEsiEmployerContribution($rule), 2),
        ];
    }
}