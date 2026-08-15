<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamConductP1 extends Model
{
    protected $table = 'exam_conduct_p1';

    protected $fillable = [
        'session_year', 'exam_date', 'inning_id', 'center_code',
    ];

    protected $casts = [
        'exam_date' => 'date',
    ];

    public function inning(): BelongsTo
    {
        return $this->belongsTo(ExamInning::class, 'inning_id');
    }
}
