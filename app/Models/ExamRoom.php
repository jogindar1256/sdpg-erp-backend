<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamRoom extends Model
{
    protected $fillable = [
        'room_no', 'building_name', 'rows', 'columns', 'capacity',
        'extra_seat', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
