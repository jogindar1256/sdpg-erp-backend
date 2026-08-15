<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintPermission extends Model
{
    protected $fillable = ['document_type', 'is_allowed'];

    protected $casts = [
        'is_allowed' => 'boolean',
    ];
}
