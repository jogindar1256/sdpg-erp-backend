<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicStaffDocument extends Model
{
    protected $fillable = ['academic_staff_id', 'doc_type', 'original_name', 'path', 'mime', 'size'];
}
