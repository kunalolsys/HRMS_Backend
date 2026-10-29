<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class StoreWeeklyOff extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * List of valid days of the week.
     *
     * @var array<int, string>
     */
    public const VALID_DAYS = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
    ];

    /**
     * Check if a given day string is a valid day of the week.
     */
    public static function isValidDay(?string $day): bool
    {
        if ($day === null || trim($day) === '') {
            return false;
        }

        return in_array(ucfirst(strtolower(trim($day))), self::VALID_DAYS, true);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'location_id',
        'weekly_off_day',
        'is_active',
        'created_by',
        'updated_by',
        'is_delete',
        'deleted_by',
        'deleted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'is_delete'  => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the location for the weekly off.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the user who created the record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the user who deleted the record.
     */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Interact with the weekly off day attribute.
     */
    protected function weeklyOffDay(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: function ($value) {
                if ($value === null) {
                    return null;
                }

                $normalized = ucfirst(strtolower(trim((string) $value)));

                if (!static::isValidDay($normalized)) {
                    throw new InvalidArgumentException(
                        "Invalid weekly off day '{$value}'. Allowed days are: " . implode(', ', self::VALID_DAYS) . '.'
                    );
                }

                return $normalized;
            },
        );
    }
}