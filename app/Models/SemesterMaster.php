<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SemesterMaster extends Model
{
    protected $fillable = ['name', 'semester_nos', 'status'];
}
