<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamUfm extends Model
{
    protected $table = 'exam_ufm';

    protected $fillable = [
        'admission_id', 'roll_no', 'paper_code', 'session_year', 'exam_date',
        'inning_id', 'room_id', 'issued_copy_no', 'invigilator1', 'ufm_by',
        'authority_name', 'ufm_copy_no2',
    ];

    protected $casts = [
        'exam_date' => 'date',
    ];

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function inning(): BelongsTo
    {
        return $this->belongsTo(ExamInning::class, 'inning_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ExamRoom::class, 'room_id');
    }
}
