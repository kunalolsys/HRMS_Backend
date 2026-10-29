<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'location_code',
        'location_name',
        'group_type',
        'state',
        'cost_center_id',
        'est_registration',
        'renewal_date',
        'biometric_device_id',
        'weekly_off_day',
        'is_active',
        'created_by',
        'updated_by',
        'is_delete',
        'deleted_by',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_delete' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the cost center associated with the location.
     */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }

    /**
     * Get the user who created the location.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the location.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function shiftLocationDesignations(): HasMany
    {
        return $this->hasMany(ShiftLocationDesignation::class);
    }

    /**
     * Interact with the location's name.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function locationName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    /**
     * Interact with the location's code.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function locationCode(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }
}