<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BroadcastLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel',
        'recipient_email',
        'recipient_name',
        'subject',
        'status',
        'error_message',
    ];
}