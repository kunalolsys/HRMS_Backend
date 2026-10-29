<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DynamicTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
        'description',
        'color',
        'mapped_column',
    ];
}
