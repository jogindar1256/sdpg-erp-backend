<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRestriction extends Model
{
    protected $fillable = [
        'student_id', 'reason', 'other_reason', 'restriction_by',
        'authority_name', 'submitted_by', 'approved_by',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
