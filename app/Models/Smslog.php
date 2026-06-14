<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'organization_id', 'student_id', 'template_id',
        'mobile', 'message', 'event_trigger',
        'status', 'provider_message_id', 'provider_response', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'template_id');
    }
}