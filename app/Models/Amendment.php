<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Amendment extends Model
{
    protected $fillable = [
        'organization_id', 'student_id', 'requested_by',
        'amendment_type', 'reference_no', 'old_data', 'new_data',
        'reason', 'status', 'approved_by', 'approved_at', 'approval_remarks',
    ];

    protected $casts = [
        'old_data'    => 'array',
        'new_data'    => 'array',
        'approved_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public static function generateReferenceNo(int $orgId, string $type): string
    {
        $prefix = strtoupper(substr($type, 0, 3));
        $count  = self::where('organization_id', $orgId)
                      ->where('amendment_type', $type)
                      ->count() + 1;
        return "AMD-{$prefix}-{$orgId}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
    }
}