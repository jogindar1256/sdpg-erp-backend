<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllottedSubject extends Model
{
    protected $fillable = [
        'program_id', 'subject_id', 'permission_type', 'for_regular', 'for_private',
    ];

    protected $casts = [
        'for_regular' => 'boolean',
        'for_private' => 'boolean',
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
