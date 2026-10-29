<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    use HasFactory;

    protected $table = 'holidays';

    protected $fillable = [
        'holiday_date',
        'holiday_title',
        'holiday_type',
        'is_active',
        'is_delete',
        'created_by',
        'updated_by',
        'deleted_by',
        'deleted_at',
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'is_active'    => 'boolean',
        'is_delete'    => 'boolean',
        'deleted_at'   => 'datetime',
    ];

    /**
     * Scope to fetch only active records.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to exclude soft-deleted records.
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('is_delete', false);
    }

    /**
     * Scope to filter holidays for a specific year.
     */
    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('holiday_date', $year);
    }

    /**
     * Scope to filter holidays for a specific month and year.
     */
    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('holiday_date', $year)
            ->whereMonth('holiday_date', $month);
    }

    /**
     * Scope to filter holidays within a date range.
     */
    public function scopeBetweenDates(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('holiday_date', [$startDate, $endDate]);
    }

    /**
     * Scope to filter full-day holidays.
     */
    public function scopeFullDay(Builder $query): Builder
    {
        return $query->where('holiday_type', 'full day');
    }

    /**
     * Scope to filter half-day holidays.
     */
    public function scopeHalfDay(Builder $query): Builder
    {
        return $query->where('holiday_type', 'half day');
    }

    protected function holidayTitle(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    protected function holidayType(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}