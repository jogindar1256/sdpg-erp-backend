<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentApplicationDocument extends Model
{
    protected $fillable = [
        'application_id', 'document_type', 'path', 'filename', 'status',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(StudentApplication::class, 'application_id');
    }
}
