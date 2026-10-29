<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StatutoryParameterMaster extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'statutory_parameter_masters';

    protected $fillable = [
        'rule_code',
        'description',
        'pf_employee_rate',
        'pf_employer_rate',
        'pf_wage_ceiling',
        'pf_eps_rate',
        'pf_eps_max_cap',
        'pf_start_month',
        'esi_employee_rate',
        'esi_employer_rate',
        'esi_gross_ceiling',
        'esi_start_month',
        'total_deduction_cap_pct',
        'effective_from',
        'effective_to',
        'is_active',
        'is_delete',
        'created_by',
        'updated_by',
        'deleted_by',
        'deleted_at',
    ];

    protected $casts = [
        'pf_employee_rate'        => 'decimal:2',
        'pf_employer_rate'        => 'decimal:2',
        'pf_wage_ceiling'         => 'decimal:2',
        'pf_eps_rate'             => 'decimal:2',
        'pf_eps_max_cap'          => 'decimal:2',
        'pf_start_month'          => 'integer',
        'esi_employee_rate'       => 'decimal:2',
        'esi_employer_rate'       => 'decimal:2',
        'esi_gross_ceiling'       => 'decimal:2',
        'esi_start_month'         => 'integer',
        'total_deduction_cap_pct' => 'decimal:2',
        'effective_from'          => 'date',
        'effective_to'            => 'date',
        'is_active'               => 'boolean',
        'is_delete'               => 'boolean',
        'deleted_at'              => 'datetime',
    ];

    /**
     * Record banane wale user ka relationship.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Record update karne wale user ka relationship.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Record delete karne wale user ka relationship.
     */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Scope: Sirf active records filter karne ke liye.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Kisi specific date par effective statutory rule nikaalne ke liye.
     */
    public function scopeEffectiveOn($query, $date = null)
    {
        $targetDate = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return $query->where('effective_from', '<=', $targetDate)
            ->where(function ($q) use ($targetDate): void {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $targetDate);
            });
    }

    /**
     * Helper: Current date ya given date ke hisaab se active rule fetch karne ke liye.
     */
    public static function getEffectiveRule($date = null): ?self
    {
        return static::query()
            ->active()
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->first();
    }
}