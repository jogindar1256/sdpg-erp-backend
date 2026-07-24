<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentLedger extends Model
{
    use SoftDeletes;

    protected $table = 'payment_ledger';

    // ── Transaction Types ─────────────────────────────────────────────────
    const TYPE_REGISTRATION       = 'Registration';        // Self-reg fee (Razorpay)
    const TYPE_ADMISSION          = 'Admission';           // Admission fee
    const TYPE_SEM_REGISTRATION   = 'Semester-Registration'; // Semester reg fee
    const TYPE_EXAMINATION        = 'Examination';         // Exam form fee
    const TYPE_BACK_PAPER         = 'Back-Paper';          // Back paper exam fee
    const TYPE_PRACTICAL          = 'Practical';           // Practical/lab exam fee
    const TYPE_UPGRADE            = 'Upgrade';             // Semester upgrade fee
    const TYPE_FINE               = 'Fine';                // Penalty/fine
    const TYPE_LATE_FEE           = 'Late-Fee';            // Late submission fee
    const TYPE_REFUND             = 'Refund';              // Money returned
    const TYPE_ADJUSTMENT         = 'Adjustment';          // Manual correction
    const TYPE_OTHER              = 'Other';

    // ── Payment Modes ─────────────────────────────────────────────────────
    const MODE_RAZORPAY = 'Online-Razorpay';
    const MODE_NEFT     = 'NEFT';
    const MODE_RTGS     = 'RTGS';
    const MODE_IMPS     = 'IMPS';
    const MODE_UPI      = 'UPI';
    const MODE_CHEQUE   = 'Cheque';
    const MODE_DD       = 'DD';
    const MODE_CASH     = 'Cash';
    const MODE_ADJ      = 'Adjustment';

    // ── Statuses ─────────────────────────────────────────────────────────
    const STATUS_PENDING   = 'Pending';
    const STATUS_SUCCESS   = 'Success';
    const STATUS_FAILED    = 'Failed';
    const STATUS_REFUNDED  = 'Refunded';
    const STATUS_CANCELLED = 'Cancelled';
    const STATUS_DISPUTED  = 'Disputed';

    protected $fillable = [
        'organization_id',
        'txn_no',
        'txn_type',
        'payment_mode',
        'amount',
        'debit',
        'credit',
        'balance',
        'status',
        'session_year',
        'semester_no',
        'student_id',
        'admission_id',
        'application_id',
        'reg_no',
        'bank_ref_no',
        'utr_no',
        'cheque_dd_no',
        'cheque_dd_date',
        'bank_name',
        'bank_account',
        'gateway_order_id',
        'gateway_payment_id',
        'gateway_signature',
        'gateway_status',
        'receipt_no',
        'paid_at',
        'description',
        'remarks',
        'created_by',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'debit'       => 'decimal:2',
        'credit'      => 'decimal:2',
        'balance'     => 'decimal:2',
        'paid_at'     => 'datetime',
        'verified_at' => 'datetime',
        'cheque_dd_date' => 'date',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function student()
    {
        return $this->belongsTo(\App\Models\Student::class);
    }

    public function admission()
    {
        return $this->belongsTo(\App\Models\Admission::class);
    }

    public function application()
    {
        return $this->belongsTo(\App\Models\Application::class);
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function verifier()
    {
        return $this->belongsTo(\App\Models\User::class, 'verified_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeByType($q, string $type)
    {
        return $q->where('txn_type', $type);
    }

    public function scopeBySession($q, string $session)
    {
        return $q->where('session_year', $session);
    }

    public function scopeByStudent($q, int $studentId)
    {
        return $q->where('student_id', $studentId);
    }

    public function scopeByStatus($q, string $status)
    {
        return $q->where('status', $status);
    }

    public function scopeSuccessful($q)
    {
        return $q->where('status', self::STATUS_SUCCESS);
    }

    public function scopePending($q)
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopeOnline($q)
    {
        return $q->where('payment_mode', self::MODE_RAZORPAY);
    }

    public function scopeOffline($q)
    {
        return $q->whereNotIn('payment_mode', [self::MODE_RAZORPAY]);
    }

    // ── Core Static Helper ────────────────────────────────────────────────

    /**
     * Record a transaction. Generates txn_no and receipt_no automatically.
     *
     * @param array $data  Any subset of $fillable fields
     * @return static
     */
    public static function record(array $data): static
    {
        $session = $data['session_year'] ?? date('Y') . '-' . (date('Y') + 1);

        // TXN-2526-000001
        $data['txn_no'] = $data['txn_no'] ?? static::generateTxnNo($session);

        // Direction: refund/adjustment = debit, everything else = credit
        $isDebit = in_array($data['txn_type'] ?? '', [self::TYPE_REFUND, self::TYPE_ADJUSTMENT]);
        if (!isset($data['credit']) && !isset($data['debit'])) {
            if ($isDebit) {
                $data['debit']  = $data['amount'];
                $data['credit'] = 0;
            } else {
                $data['credit'] = $data['amount'];
                $data['debit']  = 0;
            }
        }

        // Receipt number (only for successful transactions)
        if (
            ($data['status'] ?? '') === self::STATUS_SUCCESS
            && empty($data['receipt_no'])
            && $data['txn_type'] !== self::TYPE_REFUND
        ) {
            $data['receipt_no'] = static::generateReceiptNo($session);
        }

        // paid_at defaults to now for successful
        if (($data['status'] ?? '') === self::STATUS_SUCCESS && empty($data['paid_at'])) {
            $data['paid_at'] = now();
        }

        // Default creator = authenticated user
        if (empty($data['created_by']) && auth()->check()) {
            $data['created_by'] = auth()->id();
        }

        return static::create($data);
    }

    /**
     * Mark a pending transaction as successful (e.g. after Razorpay callback).
     */
    public function markSuccess(array $gatewayData = []): static
    {
        $update = array_merge([
            'status'  => self::STATUS_SUCCESS,
            'paid_at' => now(),
        ], $gatewayData);

        if (empty($this->receipt_no)) {
            $update['receipt_no'] = static::generateReceiptNo($this->session_year);
        }

        $this->update($update);
        return $this;
    }

    /**
     * Mark as failed.
     */
    public function markFailed(string $reason = ''): static
    {
        $this->update([
            'status'  => self::STATUS_FAILED,
            'remarks' => $reason ?: $this->remarks,
        ]);
        return $this;
    }

    // ── Number Generators ─────────────────────────────────────────────────

    public static function generateTxnNo(string $session): string
    {
        // TXN-2526-000001
        [$y1, $y2] = explode('-', $session);
        $prefix = 'TXN-' . substr($y1, 2, 2) . substr($y2, 2, 2) . '-';
        $seq    = static::where('txn_no', 'like', $prefix . '%')->count() + 1;
        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }

    public static function generateReceiptNo(string $session): string
    {
        // MR-2526-000001
        [$y1, $y2] = explode('-', $session);
        $prefix = 'MR-' . substr($y1, 2, 2) . substr($y2, 2, 2) . '-';
        $seq    = static::whereNotNull('receipt_no')
                        ->where('receipt_no', 'like', $prefix . '%')
                        ->count() + 1;
        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }

    // ── Summary Helpers ───────────────────────────────────────────────────

    /**
     * Total collected by type and session.
     *
     * Example: PaymentLedger::totalByType('2025-2026')
     * Returns: ['Registration' => 45000, 'Examination' => 120000, ...]
     */
    public static function totalByType(string $session): array
    {
        return static::successful()
            ->bySession($session)
            ->groupBy('txn_type')
            ->selectRaw('txn_type, SUM(credit) as total')
            ->pluck('total', 'txn_type')
            ->toArray();
    }

    /**
     * All transactions for a student (with receipt-ready format).
     */
    public static function studentStatement(int $studentId, string $session = null): \Illuminate\Database\Eloquent\Collection
    {
        return static::byStudent($studentId)
            ->when($session, fn($q) => $q->bySession($session))
            ->orderBy('paid_at')
            ->get();
    }
}
