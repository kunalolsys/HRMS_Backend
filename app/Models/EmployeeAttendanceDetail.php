<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAttendanceDetail extends Model
{
    use HasFactory;

    protected $table = 'employee_attendance_details';

    protected $fillable = [
        'employee_attendance_id',
        'in_out_time',
    ];

    protected function casts(): array
    {
        return [
            'in_out_time' => 'datetime',
        ];
    }

    /**
     * Get the parent attendance record.
     */
    public function employeeAttendance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAttendance::class, 'employee_attendance_id');
    }
}