<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeReceipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'student_id', 'admission_id', 'academic_year', 'semester_no',
        'receipt_type', 'receipt_no', 'receipt_date',
        'total_amount', 'late_fine', 'concession', 'net_amount',
        'payment_mode', 'transaction_id', 'bank_name', 'dd_no', 'dd_date',
        'fee_breakdown', 'is_verified', 'verified_by', 'verified_at',
        'generated_by', 'pdf_path', 'status', 'cancel_reason',
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'dd_date' => 'date',
        'verified_at' => 'datetime',
        'fee_breakdown' => 'array',
        'is_verified' => 'boolean',
        'total_amount' => 'decimal:2',
        'late_fine' => 'decimal:2',
        'concession' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function admission(): BelongsTo { return $this->belongsTo(Admission::class); }
    public function generatedBy(): BelongsTo { return $this->belongsTo(User::class, 'generated_by'); }
    public function verifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }

    public static function generateReceiptNo(int $orgId, string $academicYear, string $type): string
    {
        $prefix = match($type) {
            'regular_admission' => 'FR',
            'back_paper'        => 'BP',
            'semester_upgrade'  => 'SU',
            default             => 'MS',
        };
        $year = str_replace('-', '', $academicYear);
        $count = self::where('organization_id', $orgId)
                     ->where('academic_year', $academicYear)
                     ->where('receipt_type', $type)
                     ->count() + 1;
        return "{$prefix}-{$orgId}-{$year}-" . str_pad($count, 6, '0', STR_PAD_LEFT);
    }
}
