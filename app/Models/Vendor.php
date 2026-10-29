<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
class Vendor extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'vendor_code',
        'vendor_name',
        'gstin_number',
        'phone_number',
        'registered_address',
        'is_active',
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
     * The brands that belong to the vendor.
     */
    public function brands()
    {
        return $this->belongsToMany(Brand::class, 'vendor_brands', 'vendor_id', 'brand_id')->withTimestamps();
    }

    /**
     * Get the user who created the vendor.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    /**
     * Get the user who last updated the vendor.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function vendorCode(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtoupper(trim($value)) : $value,
        );
    }

    protected function vendorName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    protected function gstinNumber(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtoupper(trim($value)) : $value,
        );
    }

    protected function registeredAddress(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? trim($value) : $value,
        );
    }
}