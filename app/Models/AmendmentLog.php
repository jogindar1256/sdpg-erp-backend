<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmendmentLog extends Model
{
    protected $fillable = [
        'student_id', 'admission_id', 'action_type', 'changed_data',
        'ref_no', 'modified_by', 'approved_by', 'approved_at', 'status',
    ];

    protected $casts = [
        'changed_data' => 'array',
        'approved_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }
}
