<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftLocationDesignation extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'shift_location_designations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'location_id',
        'shift_id',
        'designation_id',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->where('is_delete', false)->where('is_active', true);
    }
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->where('is_delete', false)->where('is_active', true);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class)->where('is_delete', false)->where('is_active', true);
    }
}