<?php
namespace App\Models;

// 1. In top imports mein 'Laravel\Sanctum\HasApiTokens' zaroor hona chahiye:
use Illuminate\Database\Eloquent\Casts\Attribute;
use Laravel\Sanctum\HasApiTokens; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{   
    // 2. Class ke andar trait use karein:
    use HasApiTokens, HasFactory, Notifiable, HasRoles;
    protected $guard_name = 'web';
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Interact with the user's name.
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }

    /**
     * Interact with the user's email.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : $value,
            set: fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
        );
    }
    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    // Received messages
    public function receivedMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }
}
