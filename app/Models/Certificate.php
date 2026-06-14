<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Certificate extends Model
{
    protected $fillable = [
        'organization_id', 'student_id', 'certificate_type',
        'certificate_no', 'issue_date', 'certificate_data',
        'pdf_path', 'is_issued', 'issued_by', 'issued_at',
        'is_cancelled', 'cancel_reason',
    ];

    protected $casts = [
        'issue_date'       => 'date',
        'issued_at'        => 'datetime',
        'certificate_data' => 'array',
        'is_issued'        => 'boolean',
        'is_cancelled'     => 'boolean',
    ];

    protected $appends = ['pdf_url'];

    public function getPdfUrlAttribute(): ?string
    {
        return $this->pdf_path ? Storage::url($this->pdf_path) : null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public static function generateCertificateNo(int $orgId, string $type): string
    {
        $prefix = match($type) {
            'tc'          => 'TC',
            'migration'   => 'MIG',
            'character'   => 'CC',
            'bonafide'    => 'BF',
            'degree'      => 'DEG',
            'provisional' => 'PRV',
            'marksheet'   => 'MRK',
            default       => 'CERT',
        };
        $year  = now()->format('Y');
        $count = self::where('organization_id', $orgId)
                     ->where('certificate_type', $type)
                     ->whereYear('created_at', $year)
                     ->count() + 1;
        return "{$prefix}-{$orgId}-{$year}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
    }
}