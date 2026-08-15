<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamInning extends Model
{
    protected $fillable = [
        'center_code', 'inning_name', 'time_start', 'time_end',
    ];
}
