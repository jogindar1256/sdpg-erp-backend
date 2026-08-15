<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamCentre extends Model
{
    protected $fillable = [
        'center_code', 'center_name', 'college_code', 'college_name',
    ];
}
