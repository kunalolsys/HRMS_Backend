<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'employees';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'is_active',
        'permanent_unique_id',
        'company_id',
        'location_id',
        'division_id',
        'employee_code',
        'employee_name',
        'mobile_number',
        'email_id',
        'telegram_username',
        'chat_id',
        'pan_number',
        'blood_group',
        'aadhar_number',
        'gender',
        'designation_id',
        'age',
        'job_status',
        'ecode',
        'birthday_date',
        'status',
        'mode',
        'father_name',
        'current_address',
        'permanent_address',
        'marital_status',
        'spouse_name',
        'anniversary_date',
        'shift_id',
        'basic_salary',
        'date_of_join',
        'date_of_leave',
        'pf_number',
        'uan_number',
        'esi_number',
        'bank_name',
        'account_number',
        'ifsc_number',
        'weekly_off',
        'brand_staff',
        'brand_id',
        'vendor_id',
        'employee_type',
        'employee_photo',
        'emergency_contact_person_name',
        'emergency_contact_person_relation',
        'emergency_contact_person_phone_number',
        'reporting_dm',
        'reporting_sm',
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
        'is_active' => 'boolean',
        'is_delete' => 'boolean',        
        'birthday_date' => 'date',
        'anniversary_date' => 'date',
        'date_of_join' => 'date',
        'date_of_leave' => 'date',
        'basic_salary' => 'decimal:2',
        'deleted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function reportingDmUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporting_dm');
    }

    public function reportingSmUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporting_sm');
    }

    protected function employeeCode(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtoupper(trim($value)) : $value,
        );
    }

    protected function employeeName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    protected function emailId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    protected function panNumber(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtoupper(trim($value)) : $value,
        );
    }

    protected function bloodGroup(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtoupper(trim($value)) : $value,
        );
    }

    protected function gender(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    protected function jobStatus(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    protected function ecode(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtoupper(trim($value)) : $value,
        );
    }

    protected function fatherName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    protected function currentAddress(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? trim($value) : $value,
        );
    }

    protected function permanentAddress(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? trim($value) : $value,
        );
    }

    protected function maritalStatus(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    protected function spouseName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    protected function pfNumber(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtoupper(trim($value)) : $value,
        );
    }

    protected function uanNumber(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtoupper(trim($value)) : $value,
        );
    }

    protected function esiNumber(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtoupper(trim($value)) : $value,
        );
    }

    protected function bankName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    protected function ifscNumber(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtoupper(trim($value)) : $value,
        );
    }

    protected function employeeType(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }
}