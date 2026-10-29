<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MinimumWageMaster extends Model
{
    use HasFactory;

    protected $table = 'minimum_wage_masters';

    protected $fillable = [
        'state_code',
        'skill_category',
        'basic_minimum_wage',
        'vda_amount',
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
        'basic_minimum_wage' => 'decimal:2',
        'vda_amount'         => 'decimal:2',
        'effective_from'     => 'date',
        'effective_to'       => 'date',
        'is_active'          => 'boolean',
        'is_delete'          => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

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
     * Total wage accessor (Basic Minimum Wage + VDA Amount).
     */
    public function getTotalMinimumWageAttribute(): float
    {
        return (float) ($this->basic_minimum_wage + ($this->vda_amount ?? 0));
    }
}