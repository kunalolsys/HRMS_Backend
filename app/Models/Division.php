<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Division extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
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
        ];
    }
   
    /**
     * Get the user who created the division.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the division.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }
}