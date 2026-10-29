<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LateDeductionSlab extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'late_deduction_slabs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'lates_count',
        'days_deducted',
        'is_active',
        'is_delete',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'deleted_by',
        'deleted_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'lates_count'   => 'integer',
        'days_deducted' => 'float',
        'is_active'     => 'boolean',
        'is_delete'     => 'boolean',
        'deleted_at'    => 'datetime'
    ];

    public static function getDeductionForCount(int $count): float
    {
        return (float) (static::where('lates_count', '<=', $count)
            ->orderByDesc('lates_count')
            ->value('days_deducted') ?? 0.0);
    }
}