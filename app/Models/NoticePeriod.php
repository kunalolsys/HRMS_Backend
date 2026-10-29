<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoticePeriod extends Model
{
    use HasFactory;

    protected $table = 'notice_periods';

    protected $fillable = [
        'designation_id',
        'notice_period_days',
        'probation_notice_period_days',
        'description',
        'is_active',
        'is_delete',
        'deleted_by',
        'deleted_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_delete' => 'boolean',
        'deleted_at' => 'datetime',
        'notice_period_days' => 'integer',
        'probation_notice_period_days' => 'integer',
    ];

    /**
     * Get the designation associated with the notice period.
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    /**
     * Audit tracking relationships.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}