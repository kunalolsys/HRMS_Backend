<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryBifurcation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'actual_salary',
        'books_basic',
        'bonus',
        'books_hra',
        'other_allowances',
        'total_salary',
        'effective_start_date',
        'effective_end_date',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
        'deleted_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actual_salary'        => 'decimal:2',
            'books_basic'          => 'decimal:2',
            'bonus'                => 'decimal:2',
            'books_hra'            => 'decimal:2',
            'other_allowances'     => 'decimal:2',
            'total_salary'         => 'decimal:2',
            'effective_start_date' => 'date',
            'effective_end_date'   => 'date',
            'is_active'            => 'boolean',
            'is_deleted'           => 'boolean',
        ];
    }

    /**
     * Get the user who created this bifurcation.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the user who deleted this bifurcation.
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}