<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounsellingReport extends Model
{
    protected $fillable = [
        'program_id', 'session_year', 'entrance_roll_no', 'name', 'father_name',
        'mother_name', 'spouse_name', 'gender', 'social_category', 'admission_category',
        'admission_type', 'state_rank', 'category_rank', 'cut_off_mark', 'entry_date',
    ];

    protected $casts = [
        'entry_date' => 'date',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
