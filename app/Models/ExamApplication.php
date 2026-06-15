<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamApplication extends Model
{
    protected $fillable = [
        'examination_id', 'student_id', 'organization_id',
        'form_no', 'applied_date', 'subjects_appearing',
        'fee_paid', 'payment_ref', 'status',
        'admit_card_path', 'admit_card_generated',
        'approved_by', 'approved_at',
    ];

    protected $casts = [
        'applied_date'          => 'date',
        'approved_at'           => 'datetime',
        'subjects_appearing'    => 'array',
        'fee_paid'              => 'decimal:2',
        'admit_card_generated'  => 'boolean',
    ];

    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public static function generateFormNo(int $orgId, int $examId): string
    {
        $count = self::where('examination_id', $examId)->count() + 1;
        return "EF-{$orgId}-{$examId}-" . str_pad($count, 6, '0', STR_PAD_LEFT);
    }
}