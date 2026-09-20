<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per hold-or-reject DECISION on a student_applications row.
 * Student data is never duplicated here — it's always read through
 * studentApplication()->student, the trusted linkup key is
 * student_application_id. See the create_rejected_applications_table
 * migration docblock for the full field-by-field reasoning.
 */
class RejectedApplication extends Model
{
    protected $fillable = [
        'organization_id', 'student_application_id', 'ref_no',
        'decision', 'status', 'hold_type', 'reason', 'objections',
        'submitted_by', 'submitted_at',
        'decided_by', 'decided_at',
        'released_by', 'released_at', 'release_due_to', 'release_remarks',
    ];

    protected $casts = [
        'objections'   => 'array',
        'submitted_at' => 'datetime',
        'decided_at'   => 'datetime',
        'released_at'  => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function studentApplication(): BelongsTo
    {
        return $this->belongsTo(StudentApplication::class, 'student_application_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
