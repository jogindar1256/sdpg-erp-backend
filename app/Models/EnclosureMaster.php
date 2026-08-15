<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnclosureMaster extends Model
{
    protected $fillable = [
        'program_id', 'semester_no', 'admission_mode', 'document_name',
        'is_required', 'condition', 'enclose', 'scan_copy', 'photo_count',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'enclose' => 'boolean',
        'scan_copy' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
