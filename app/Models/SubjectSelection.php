<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectSelection extends Model
{
    protected $fillable = [
        'program_id', 'semester_no', 'subject_id', 'group_no', 'group_label',
        'group_name', 'is_compulsory', 'max_marks', 'min_marks',
        'max_select', 'min_select', 'sort_order',
    ];

    protected $casts = [
        'is_compulsory' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
