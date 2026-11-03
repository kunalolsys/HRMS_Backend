<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use HasFactory;

    protected $table = 'payroll_runs';

    protected $fillable = [
        'company_id',
        'payroll_year',
        'payroll_month',
        'status',
        'run_by_user',
        'run_timestamp',
        'locked_by',
        'locked_at',
        'total_employees',
        'total_gross_paid',
        'total_net_paid',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payroll_year'     => 'integer',
            'payroll_month'    => 'integer',
            'run_timestamp'    => 'datetime',
            'locked_at'        => 'datetime',
            'total_employees'  => 'integer',
            'total_gross_paid' => 'decimal:2',
            'total_net_paid'   => 'decimal:2',
        ];
    }

    /**
     * Company associated with the payroll run.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * User who queued or executed the payroll run.
     */
    public function runByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by_user');
    }

    /**
     * Checker/approver user who locked the payroll run.
     */
    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * Payslips generated during this payroll run.
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'payroll_run_id');
    }
}