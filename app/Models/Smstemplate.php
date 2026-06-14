<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsTemplate extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'event_trigger', 'template',
        'dlt_template_id', 'sender_id', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SmsLog::class, 'template_id');
    }

    /**
     * Render the template with actual values.
     * e.g. "Dear {student_name}" → "Dear Jogindar"
     */
    public function render(array $variables): string
    {
        $message = $this->template;
        foreach ($variables as $key => $value) {
            $message = str_replace("{{$key}}", $value, $message);
        }
        return $message;
    }
}