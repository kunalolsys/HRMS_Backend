<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'shifts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'is_active',
        'shift_name',
        'shift_code',
        'shift_start_time', // Assuming this is timing_from
        'shift_end_time', // Assuming this is timing_end
        'grace_time',
        'full_day_min_hours',
        'short_leave_min_hours',
        'half_day_min_hours',
        'no_salary_max_hours',
        'effective_from',
        'effective_to',
        'created_by',
        'updated_by',
        'is_delete',
        'deleted_by',
        'deleted_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'shift_start_time' => 'datetime:H:i:s',
        'shift_end_time' => 'datetime:H:i:s',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
        'is_delete' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the location that owns the shift.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the designation that owns the shift.
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function shiftLocationDesignations(): HasMany
    {
        return $this->hasMany(ShiftLocationDesignation::class);
    }

    protected function shiftName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    protected function shiftCode(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtoupper(trim($value)) : $value,
        );
    }
}