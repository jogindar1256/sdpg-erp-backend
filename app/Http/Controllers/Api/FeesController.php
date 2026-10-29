<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesStudentIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class FeesController extends Controller
{
    use ResolvesStudentIdentity;

    /** Compose a display name from a row carrying dr.name + s.first/middle/last_name. */
    private function composeName(?string $regName, ?string $first, ?string $middle, ?string $last): string
    {
        if (!empty($regName)) return $regName;
        return trim(implode(' ', array_filter([$first, $middle, $last])));
    }

    /** Frontend-facing status label from the real is_verified/status columns. */
    private function statusLabel(bool $isVerified, string $status): string
    {
        if ($status === 'cancelled') return 'Rejected';
        return $isVerified ? 'Verified' : 'Pending';
    }

    /**
     * Map a student's own gender/category onto fee_structures.amount_json's
     * key spelling. Same intentional mismatches handled in
     * ApplicationController::feeGenderCategory() — students.gender's
     * 'other' -> 'transgender'; students.category's default 'general' ->
     * 'gen'.
     */
    private function feeGenderCategory(?string $gender, ?string $category): array
    {
        $gender = strtolower((string) ($gender ?? 'male'));
        $gender = $gender === 'other' ? 'transgender' : $gender;

        $category = strtolower((string) ($category ?? 'general'));
        $category = $category === 'general' ? 'gen' : $category;

        return [$gender, $category];
    }

    // ─── Shared: base receipt query ──────────────────────────────────────────────
    protected function baseReceiptQuery(Request $request)
    {
        $latestReg = $this->latestRegistrationSub();

        $q = DB::table('fee_receipts as fr')
            // students is a LEFT join: a first-time applicant's receipt is
            // issued at payment, before their students row exists (it is
            // created when this receipt is verified). The applicant is then
            // identified through the admission instead.
            ->leftJoin('students as s', 's.id', '=', 'fr.student_id')
            ->leftJoin('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->leftJoin('programs as p', 'p.id', '=', 'adm.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', '=', DB::raw('COALESCE(s.user_id, adm.user_id)'))
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoin('student_applications as sa', 'sa.id', '=', 'adm.application_id')
            ->leftJoin('fee_refunds as rf', 'rf.fee_receipt_id', '=', 'fr.id')
            ->select([
                'sa.application_no', 'dr.category',
                'fr.id', 'fr.receipt_no', 'fr.receipt_type as fee_type', 'fr.net_amount as amount',
                'fr.transaction_id as utr_no', 'fr.bank_ref_no', 'fr.receipt_date as payment_date',
                'fr.is_verified', 'fr.status', 'fr.created_at',
                'fr.cancel_reason', 'rf.status as refund_status', 'rf.refund_amount', 'rf.refund_ref', 'rf.refunded_at',
                's.personal_info->first_name as first_name', 's.personal_info->middle_name as middle_name', 's.personal_info->last_name as last_name',
                'dr.name as reg_name', 'dr.father_name',
                'p.short_name as class_name', 'fr.semester_no',
                'fr.admission_id', 'adm.admission_no',
                DB::raw('COALESCE(s.student_uid, sa.student_uid) as student_uid'),
                DB::raw('(SELECT name FROM users WHERE id = fr.generated_by LIMIT 1) as issued_by'),
            ]);

        if ($s = $request->session)      $q->where('fr.academic_year', $s);
        if ($c = $request->class_id)     $q->where('adm.program_id', $c);
        if ($sem = $request->semester)   $q->where('fr.semester_no', $sem);
        if ($t = $request->fee_type)     $q->where('fr.receipt_type', $t);
        if ($st = $request->status) {
            if ($st === 'Verified')      $q->where('fr.is_verified', true);
            elseif ($st === 'Pending')   $q->where('fr.is_verified', false)->where('fr.status', '!=', 'cancelled');
            elseif ($st === 'Rejected')  $q->where('fr.status', 'cancelled');
        }
        if ($df = $request->date_from)   $q->whereDate('fr.receipt_date', '>=', $df);
        if ($dt = $request->date_to)     $q->whereDate('fr.receipt_date', '<=', $dt);

        if ($search = $request->search) {
            $q->where(function ($w) use ($search) {
                $w->where('s.personal_info->first_name', 'ilike', "%$search%")
                  ->orWhere('s.personal_info->last_name', 'ilike', "%$search%")
                  ->orWhere('dr.name', 'ilike', "%$search%")
                  ->orWhere('adm.applicant_info->name', 'ilike', "%$search%")
                  ->orWhere('adm.admission_no', 'ilike', "%$search%")
                  ->orWhere('fr.transaction_id', 'ilike', "%$search%")
                  ->orWhere('fr.receipt_no', 'ilike', "%$search%");
            });
        }

        return $q;
    }

    private function decorate($row)
    {
        $row->student_name = $this->composeName($row->reg_name, $row->first_name, $row->middle_name, $row->last_name);
        $row->status_label  = $this->statusLabel((bool) $row->is_verified, $row->status);
        return $row;
    }

    // ─── All Fee Receipts: GET /fees/receipts ────────────────────────────────────
    public function receiptsIndex(Request $request): JsonResponse
    {
        $q = $this->baseReceiptQuery($request);
        // ?pending_first=1 (Authorization → Fee Receipt): unverified first, oldest first.
        $q = $request->boolean('pending_first')
            ? $q->orderBy('fr.is_verified')->orderBy('fr.created_at')
            : $q->orderByDesc('fr.created_at');

        $perPage = 20;
        $data    = $q->forPage($request->page ?? 1, $perPage)->get()
            ->map(fn ($r) => $this->decorate($r));

        // Aggregated in Postgres over the same filtered join — previously every
        // matching receipt was loaded into PHP just to count it. Its total is
        // the pagination total too, so no separate count() query.
        $agg = $this->baseReceiptQuery($request)
            ->select([])
            ->selectRaw("COUNT(*) AS total,
                COUNT(*) FILTER (WHERE fr.is_verified) AS verified,
                COUNT(*) FILTER (WHERE NOT fr.is_verified AND fr.status IS DISTINCT FROM 'cancelled') AS pending,
                COALESCE(SUM(fr.net_amount) FILTER (WHERE fr.is_verified), 0) AS total_amount")
            ->first();
        $total   = (int) $agg->total;
        $summary = [
            'total'        => $total,
            'verified'     => (int) $agg->verified,
            'pending'      => (int) $agg->pending,
            'total_amount' => (float) $agg->total_amount,
        ];

        return response()->json([
            'data'    => $data,
            'meta'    => ['total' => $total, 'last_page' => (int) ceil($total / $perPage), 'per_page' => $perPage],
            'summary' => $summary,
            'classes' => DB::table('programs')->where('is_active', true)->whereNull('deleted_at')->select('id', 'short_name as name')->orderBy('short_name')->get(),
        ]);
    }

    // ─── Final Fee Receipt: GET /fees/generate, /fees/generate/preview, /fees/generate/{id}/pdf ──
    // Lists the education-fee receipts already PAID (Applications → Edu. Fee →
    // Pay) for a type; one student at a time, the PDF route renders the
    // "Final Fee Receipt" on first print and downloads it.
    private const GENERATE_TYPES = ['regular_admission', 'back_paper', 'semester_upgrade'];

    public function generateIndex(Request $request): JsonResponse
    {
        $type = in_array($request->type, self::GENERATE_TYPES, true) ? $request->type : 'regular_admission';

        $rows = DB::table('fee_receipts as fr')
            ->leftJoin('admissions as a', 'a.id', '=', 'fr.admission_id')
            ->leftJoin('student_applications as sa', 'sa.id', '=', 'a.application_id')
            // The admission's own registration, else its application's.
            ->leftJoin('direct_registrations as dr', 'dr.id', '=', DB::raw('COALESCE(a.direct_registration_id, sa.direct_registration_id)'))
            ->leftJoin('students as s', 's.id', '=', 'fr.student_id')
            ->leftJoin('programs as p', 'p.id', '=', 'a.program_id')
            ->whereNull('fr.deleted_at')
            ->where('fr.status', '!=', 'cancelled')
            ->where('fr.receipt_type', $type)
            ->when($request->session_year, fn($q, $v) => $q->where('fr.academic_year', $v))
            ->when($request->program_id, fn($q, $v) => $q->where('a.program_id', $v))
            ->when($request->search, function ($q, $v) {
                $q->where(function ($w) use ($v) {
                    $w->where('dr.name', 'ilike', "%{$v}%")
                        ->orWhere('dr.registration_no', 'ilike', "%{$v}%")
                        ->orWhere('fr.receipt_no', 'ilike', "%{$v}%")
                        ->orWhere('sa.application_no', 'ilike', "%{$v}%")
                        ->orWhere('a.admission_no', 'ilike', "%{$v}%")
                        ->orWhere('sa.student_uid', 'ilike', "%{$v}%")
                        ->orWhere('s.student_uid', 'ilike', "%{$v}%")
                        ->orWhere('dr.mobile', 'ilike', "%{$v}%")
                        ->orWhere('s.mobile', 'ilike', "%{$v}%");
                });
            })
            ->orderByDesc('fr.id')
            ->limit(500)
            ->get([
                'fr.id', 'fr.receipt_no', 'fr.semester_no', 'fr.net_amount', 'fr.receipt_date',
                'fr.final_receipt_generated_at',
                'dr.registration_no',
                'a.admission_no', 'sa.application_no', 'sa.student_uid as app_student_uid',
                's.student_uid as student_student_uid', 's.university_roll_no',
                'dr.name as reg_name', 's.personal_info as s_info',
                'p.id as program_id', 'p.short_name', 'p.full_name', 'p.samarth_code', 'p.level',
            ])
            ->map(function ($r) {
                $info = is_string($r->s_info) ? (json_decode($r->s_info, true) ?: []) : [];
                $name = $r->reg_name ?: trim(implode(' ', array_filter([$info['first_name'] ?? null, $info['middle_name'] ?? null, $info['last_name'] ?? null])));
                return [
                    'id' => $r->id,
                    'receipt_no' => $r->receipt_no,
                    'student' => ['name' => $name ?: null],
                    'roll_no' => $r->university_roll_no,
                    'student_uid' => $r->student_student_uid ?: $r->app_student_uid,
                    'registration_no' => $r->registration_no,
                    'application_no' => $r->application_no,
                    'admission_no' => $r->admission_no,
                    'program' => ['id' => $r->program_id, 'short_name' => $r->short_name, 'full_name' => $r->full_name, 'samarth_code' => $r->samarth_code, 'level' => $r->level],
                    'semester' => $r->semester_no ?: 1,
                    'fee_amount' => (float) $r->net_amount,
                    'receipt_date' => $r->receipt_date,
                    'receipt_generated' => !empty($r->final_receipt_generated_at),
                ];
            });

        return response()->json(['data' => $rows, 'total' => $rows->count()]);
    }

    /**
     * GET /fees/generate/preview?q=&type=&session_year=
     * One student's Final Fee Receipt preview — the record behind the
     * Generate link. q is an exact registration no. / application no. /
     * student ID / mobile (also admission no. or receipt no.); the latest
     * paid receipt of the type (and session, when given) is used.
     */
    public function generatePreview(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['message' => 'Enter a Registration No., Application No., Student ID or Mobile No.'], 422);
        }
        $type = in_array($request->type, self::GENERATE_TYPES, true) ? $request->type : 'regular_admission';
        $lower = mb_strtolower($q);

        $hit = DB::table('fee_receipts as fr')
            ->join('admissions as a', 'a.id', '=', 'fr.admission_id')
            ->leftJoin('student_applications as sa', 'sa.id', '=', 'a.application_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', '=', DB::raw('COALESCE(a.direct_registration_id, sa.direct_registration_id)'))
            ->leftJoin('students as s', 's.id', '=', 'a.student_id')
            ->whereNull('fr.deleted_at')
            ->where('fr.status', '!=', 'cancelled')
            ->where('fr.receipt_type', $type)
            ->when($request->session_year, fn($w, $v) => $w->where('fr.academic_year', $v))
            ->where(function ($w) use ($lower, $q) {
                $w->whereRaw('LOWER(dr.registration_no) = ?', [$lower])
                    ->orWhereRaw('LOWER(sa.application_no) = ?', [$lower])
                    ->orWhereRaw('LOWER(s.student_uid) = ?', [$lower])
                    ->orWhereRaw('LOWER(sa.student_uid) = ?', [$lower])
                    ->orWhereRaw('LOWER(a.admission_no) = ?', [$lower])
                    ->orWhereRaw('LOWER(fr.receipt_no) = ?', [$lower])
                    ->orWhere('dr.mobile', $q)
                    ->orWhere('s.mobile', $q);
            })
            ->orderByDesc('fr.id')
            ->first(['fr.id as receipt_id', 'a.id as admission_id', 'dr.id as registration_id']);

        if (!$hit) {
            return response()->json(['message' => "No paid {$this->generateTypeLabel($type)} fee receipt found for \"{$q}\"."], 404);
        }

        $receipt = DB::table('fee_receipts')->where('id', $hit->receipt_id)->first();
        $admission = DB::table('admissions')->where('id', $hit->admission_id)->first();
        $card = app(ApplicationController::class)->verificationCardData($admission) ?? [];
        $reg = $hit->registration_id ? DB::table('direct_registrations')->where('id', $hit->registration_id)->first() : null;
        $student = $admission->student_id ? \App\Models\Student::flat(DB::table('students')->where('id', $admission->student_id)->first()) : null;
        $app = $admission->application_id ? DB::table('student_applications')->where('id', $admission->application_id)->first(['id', 'part_2', 'part_6', 'program_id', 'academic_year', 'semester_no', 'student_uid']) : null;

        $part2 = $app && $app->part_2 ? (json_decode($app->part_2, true) ?: []) : [];
        $part6 = $app && $app->part_6 ? (json_decode($app->part_6, true) ?: []) : [];
        $line = fn(string $label, $v) => ($v = trim((string) $v)) !== '' ? "{$label} - {$v}" : null;
        $address = array_values(array_filter([
            implode(', ', array_filter([
                $line('Vill.', $part2['perm_house_no'] ?? $part2['perm_mohalla'] ?? null),
                $line('Post', $part2['perm_post_office'] ?? null),
            ])),
            implode(', ', array_filter([
                $line('Tehsil', $part2['perm_sub_district'] ?? null),
                $line('Dist.', $part2['perm_district'] ?? null),
            ])),
            trim(implode(' - ', array_filter([$part2['perm_state'] ?? null, $part2['perm_pin_code'] ?? null]))),
        ]));

        $userNames = DB::table('users')->whereIn('id', array_filter([$receipt->verified_by ?? null, $receipt->generated_by ?? null]))->pluck('name', 'id');
        $regPay = $reg ? \App\Support\Payments::paidRegistrationPayment((int) $reg->id) : null;
        $payments = [];
        if ($regPay) {
            $payments[] = ['description' => 'Registration Fee', 'ref_no' => $regPay->gateway_payment_id, 'amount' => (float) $regPay->amount, 'date' => $regPay->paid_at, 'approved_by' => null];
        }
        $payments[] = [
            'description' => 'Educational Fee',
            'ref_no' => $receipt->transaction_id ?: $receipt->bank_ref_no,
            'amount' => (float) $receipt->net_amount,
            'date' => $receipt->receipt_date,
            'approved_by' => $userNames[$receipt->verified_by] ?? null,
        ];
        $misc = DB::table('fee_receipts')
            ->where('admission_id', $admission->id)
            ->where('receipt_type', 'miscellaneous')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get(['id', 'receipt_no', 'net_amount', 'receipt_date', 'transaction_id', 'fee_breakdown']);
        foreach ($misc as $m) {
            $heads = collect(json_decode($m->fee_breakdown ?? '[]', true) ?: [])->pluck('fee_head_name')->filter()->implode(', ');
            $payments[] = ['description' => $heads ?: 'Miscellaneous Fee', 'ref_no' => $m->transaction_id, 'amount' => (float) $m->net_amount, 'date' => $m->receipt_date, 'approved_by' => null];
        }

        return response()->json([
            'receipt' => [
                'id' => $receipt->id,
                'receipt_no' => $receipt->receipt_no,
                'type' => $type,
                'generated' => !empty($receipt->final_receipt_generated_at),
            ],
            'registration_no' => $reg->registration_no ?? ($card['registration_no'] ?? null),
            'application_no' => $card['application_no'] ?? null,
            'admission_mode' => $this->generateTypeLabel($type),
            'course' => [
                'short_name' => $card['course'] ?? null,
                'full_name' => $card['course_full_name'] ?? null,
                'samarth_code' => $card['samarth_code'] ?? null,
            ],
            'semester_no' => $receipt->semester_no ?: ($admission->semester_no ?: 1),
            'course_year' => $receipt->academic_year,
            'enrolled' => [
                'student_uid' => ($student->student_uid ?? null) ?: ($app->student_uid ?? null),
                'receipt_no' => $receipt->receipt_no,
                'account_no' => $admission->account_no ?? null,
                'file_no' => $admission->file_no ?? null,
                'record_no' => $admission->record_no ?? null,
            ],
            'ids' => [
                'abc_id' => ($student->abc_id ?? null) ?: ($reg->abc_id ?? null),
                'ddurn_no' => $reg->ddurn_no ?? null,
                'aadhar_no' => ($student->aadhar_no ?? null) ?: ($reg->aadhar_no ?? ($card['aadhar_no'] ?? null)),
                'family_id' => $reg->family_id ?? null,
                'enrollment_no' => $student->enrollment_no ?? null,
                'univ_roll_no' => $student->university_roll_no ?? null,
                'tc_status' => $card['tc_status'] ?? null,
                'migration' => $card['migration'] ?? null,
            ],
            'personal' => [
                'name' => $card['name'] ?? ($reg->name ?? null),
                'father_name' => $card['father_name'] ?? null,
                'mother_name' => $card['mother_name'] ?? null,
                'spouse_name' => $reg->spouse_name_english ?? null,
                'dob' => $card['dob'] ?? null,
                'category' => $card['category'] ?? null,
                'gender' => $card['gender'] ?? null,
                'domestic_state' => ($reg->domestic_state ?? null) ?: ($card['state'] ?? null),
            ],
            'photo_url' => $card['photo_url'] ?? null,
            'signature_url' => $card['signature_url'] ?? null,
            'address' => $address,
            'mobile' => $card['mobile'] ?? ($reg->mobile ?? null),
            'email' => $card['email'] ?? ($reg->email ?? null),
            'subjects' => $app ? $this->previewSubjects($app, $part6) : [],
            'payments' => $payments,
            'misc_receipts' => $misc->map(fn($m) => ['id' => $m->id, 'receipt_no' => $m->receipt_no])->values(),
        ]);
    }

    /**
     * Subject | nature (Theory / Practical) | paper codes, from Part 6:
     * PG / B.Ed. pick papers, UG picks subjects (all their papers for the
     * course / session / semester).
     */
    private function previewSubjects(object $app, array $part6): array
    {
        $paperIds = collect([$part6['compulsory_paper_ids'] ?? [], $part6['optional_paper_ids'] ?? [], $part6['teaching_paper_ids'] ?? []])
            ->flatten()->filter()->map(fn($v) => (int) $v)->unique()->values()->all();

        $papers = DB::table('subject_papers as sp')
            ->leftJoin('subjects as sub', 'sub.id', '=', 'sp.subject_id')
            ->when($paperIds,
                fn($w) => $w->whereIn('sp.id', $paperIds),
                fn($w) => $w->whereIn('sp.subject_id', \App\Support\Part6Rules::subjectSlots($part6) ?: [0])
                    ->where('sp.program_id', $app->program_id)
                    ->where('sp.session_year', $app->academic_year)
                    ->where('sp.semester_no', (string) ($app->semester_no ?: 1)))
            ->orderBy('sp.subject_id')->orderBy('sp.paper_code')
            ->get(['sp.subject_id', 'sub.name as subject_name', 'sp.paper_code', 'sp.paper_type']);

        return $papers->groupBy('subject_id')->map(fn($ps) => [
            'subject' => $ps->first()->subject_name,
            'nature' => $ps->contains(fn($p) => strtolower((string) $p->paper_type) === 'practical') ? 'Practical' : 'Theory',
            'paper_codes' => $ps->pluck('paper_code')->filter()->unique()->implode(', '),
        ])->values()->all();
    }

    private function generateTypeLabel(string $type): string
    {
        return ['regular_admission' => 'Regular', 'back_paper' => 'Back Paper', 'semester_upgrade' => 'Semester Upgrade'][$type] ?? 'Regular';
    }

    /** GET /fees/generate/{id}/pdf — download final-fee-receipt-[receipt_no].pdf (renders it if needed). */
    public function generatePdf(Request $request, int $id)
    {
        $receipt = \App\Models\FeeReceipt::find($id);
        if (!$receipt) {
            return response()->json(['message' => 'Receipt not found.'], 404);
        }
        $svc = app(\App\Services\FinalFeeReceiptService::class);
        if (!$receipt->final_receipt_path || !\Illuminate\Support\Facades\Storage::exists($receipt->final_receipt_path)) {
            try {
                $svc->generate($receipt, $request->user()?->id);
                $receipt->refresh();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Final fee receipt failed for receipt {$receipt->id}: " . $e->getMessage());
                return response()->json(['message' => 'Could not generate the receipt PDF.'], 500);
            }
        }

        return \Illuminate\Support\Facades\Storage::download($receipt->final_receipt_path, \App\Services\FinalFeeReceiptService::fileName($receipt), ['Content-Type' => 'application/pdf']);
    }

    // ─── Verify Fee Receipts: GET /fees/verify ───────────────────────────────────
    public function verifyIndex(Request $request): JsonResponse
    {
        $q = $this->baseReceiptQuery($request)
            ->where('fr.is_verified', false)
            ->where('fr.status', '!=', 'cancelled')
            ->orderBy('fr.created_at');

        $data = $q->get()->map(fn ($r) => $this->decorate($r));

        // Aggregated in Postgres — previously every receipt of the session
        // (all columns) was loaded into PHP for these three numbers.
        $agg = DB::table('fee_receipts as fr')
            ->when($request->session, fn ($w, $s) => $w->where('fr.academic_year', $s))
            ->selectRaw("COUNT(*) FILTER (WHERE NOT fr.is_verified AND fr.status IS DISTINCT FROM 'cancelled') AS pending,
                COUNT(*) FILTER (WHERE fr.is_verified AND fr.verified_at::date = ?::date) AS verified_today,
                COALESCE(SUM(fr.net_amount) FILTER (WHERE NOT fr.is_verified AND fr.status IS DISTINCT FROM 'cancelled'), 0) AS pending_amount",
                [now()->toDateString()])
            ->first();

        $summary = [
            'pending'        => (int) $agg->pending,
            'verified_today' => (int) $agg->verified_today,
            'pending_amount' => (float) $agg->pending_amount,
        ];

        return response()->json([
            'data'    => $data,
            'summary' => $summary,
            'classes' => DB::table('programs')->where('is_active', true)->whereNull('deleted_at')->select('id', 'short_name as name')->orderBy('short_name')->get(),
        ]);
    }

    // ─── Refund register: GET /fees/refunds ─────────────────────────────────────
    // Every refund owed: rejected / cancelled receipts and cancelled admissions.
    public function refundsIndex(Request $request): JsonResponse
    {
        $latestReg = $this->latestRegistrationSub();
        $q = DB::table('fee_refunds as rf')
            ->leftJoin('admissions as adm', 'adm.id', '=', 'rf.admission_id')
            ->leftJoin('student_applications as sa', 'sa.id', '=', DB::raw('COALESCE(rf.student_application_id, adm.application_id)'))
            ->leftJoin('programs as p', 'p.id', '=', DB::raw('COALESCE(sa.program_id, adm.program_id)'))
            ->leftJoin('fee_receipts as fr', 'fr.id', '=', 'rf.fee_receipt_id')
            ->leftJoin('direct_registrations as drc', 'drc.id', '=', 'sa.direct_registration_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', '=', 'sa.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoin('users as ru', 'ru.id', '=', 'rf.requested_by')
            ->leftJoin('users as fu', 'fu.id', '=', 'rf.refunded_by')
            ->select([
                'rf.*',
                'sa.application_no', 'adm.admission_no', 'fr.receipt_no',
                DB::raw('COALESCE(drc.name, dr.name) as student_name'),
                DB::raw('COALESCE(drc.father_name, dr.father_name) as father_name'),
                DB::raw('COALESCE(drc.mobile, dr.mobile) as mobile'),
                'p.short_name as class_name', 'sa.semester_no',
                'ru.name as requested_by_name', 'fu.name as refunded_by_name',
            ])
            ->when($request->status, fn($q, $v) => $q->where('rf.status', strtolower($v)))
            ->when($request->session, fn($q, $v) => $q->where('rf.academic_year', $v))
            ->when($request->source, fn($q, $v) => $q->where('rf.source', $v))
            ->when($request->search, function ($q, $v) {
                $q->where(function ($w) use ($v) {
                    $w->where('sa.application_no', 'ilike', "%$v%")
                        ->orWhere('fr.receipt_no', 'ilike', "%$v%")
                        ->orWhere('adm.admission_no', 'ilike', "%$v%")
                        ->orWhere('drc.name', 'ilike', "%$v%")
                        ->orWhere('dr.name', 'ilike', "%$v%")
                        ->orWhere('drc.mobile', 'ilike', "%$v%");
                });
            })
            ->orderByRaw("CASE WHEN rf.status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('rf.id');

        $summary = DB::table('fee_refunds')
            ->when($request->session, fn($q, $v) => $q->where('academic_year', $v))
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'pending') as pending,
                         COALESCE(SUM(refund_amount) FILTER (WHERE status = 'pending'), 0) as pending_amount,
                         COUNT(*) FILTER (WHERE status = 'refunded') as refunded,
                         COALESCE(SUM(refund_amount) FILTER (WHERE status = 'refunded'), 0) as refunded_amount")
            ->first();

        return response()->json(['summary' => $summary] + $q->paginate((int) $request->input('per_page', 50))->toArray());
    }

    // ─── POST /fees/refunds/{id}/refunded ─────────────────────────────────────────
    public function refundMark(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'refund_ref' => 'required|string|max:100',
            'refund_mode' => 'nullable|string|max:30',
            'refund_remarks' => 'nullable|string|max:500',
        ]);
        [$code, $msg] = \App\Support\FeeRefunds::markRefunded($id, $request->refund_ref, $request->refund_mode, $request->refund_remarks, Auth::id());
        return response()->json(['message' => $msg], $code);
    }

    // ─── Verify: POST /fees/verify/{id}/{act} ────────────────────────────────────
    public function verifyAction(Request $request, int $id, string $act): JsonResponse
    {
        $receipt = DB::table('fee_receipts')->where('id', $id)->first();
        if (!$receipt) return response()->json(['message' => 'Receipt not found.'], 404);

        if (!in_array($act, ['verify', 'reject'])) {
            return response()->json(['message' => 'Invalid action.'], 422);
        }

        if ($receipt->status === 'cancelled') {
            return response()->json(['message' => 'This receipt is cancelled / rejected.'], 422);
        }
        if ($act === 'verify' && $receipt->is_verified) {
            return response()->json(['message' => 'Receipt already verified.'], 409);
        }

        if ($act === 'reject') {
            // Shared with FeeReceiptController::cancel — keeps the receipt,
            // marks the refund pending and puts the application back to unpaid.
            [$code, $msg] = \App\Support\ReceiptCancellation::cancel($id, $request->remarks, Auth::id());
            if ($code >= 400) {
                return response()->json(['message' => $msg], $code);
            }
        }

        if ($act === 'verify') {
            $appId = $receipt->admission_id
                ? DB::table('admissions')->where('id', $receipt->admission_id)->value('application_id')
                : null;

            try {
                DB::transaction(function () use ($id, $appId) {
                    DB::table('fee_receipts')->where('id', $id)->update([
                        'is_verified' => true,
                        'verified_by' => Auth::id(),
                        'verified_at' => now(),
                        'updated_at'  => now(),
                    ]);
                    \App\Support\Payments::markVerified($id, Auth::id());

                    // Verifying the receipt is what turns a first-time applicant into a
                    // student: the students row is created and the admission becomes
                    // active (linked to that student) for exams, ledgers and the rest.
                    if ($appId) {
                        app(ApplicationController::class)->finalizeAdmissionAfterReceipt((int) $appId, (int) Auth::id());

                        $app = DB::table('student_applications')->where('id', $appId)->first();
                        $mustBeStudent = $app
                            && $app->status === 'approved'
                            && \App\Support\Payments::applicationFeePaid((int) $appId);
                        $admStudent = DB::table('admissions')->where('application_id', $appId)->orderByDesc('id')->value('student_id');
                        if ($mustBeStudent && !$admStudent) {
                            throw new \RuntimeException('student_not_created');
                        }
                    }
                });
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'student_not_created') throw $e;
                return response()->json([
                    'message' => 'Could not create the student record, so the receipt was NOT verified: ' . (ApplicationController::$lastStudentError ?? 'unknown reason — see the server log') . '.',
                ], 422);
            }
            // The verified admission now shows up for the examination office
            // (Accept Exam Form) — best effort, never blocks verification.
            if ($receipt->admission_id) {
                \App\Support\ExamFormSeeder::ensureForAdmission((int) $receipt->admission_id);
            }
            $status = 'Verified';
        } else {
            $status = 'Rejected';
        }

        // authorization_logs is real and matches these columns as-is.
        DB::table('authorization_logs')->insert([
            'action_type'  => 'FeeReceiptVerification',
            'action'       => $status,
            'admission_id' => $receipt->admission_id,
            'reference_id' => $id,
            'remarks'      => $request->remarks,
            'performed_by' => Auth::id(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => "Receipt {$status} successfully."]);
    }

    // ─── Student Ledger: GET /fees/ledger ────────────────────────────────────────
    public function ledgerIndex(Request $request): JsonResponse
    {
        $query = $request->input('query');
        if (!$query) return response()->json(['message' => 'Query required.'], 422);

        $latestReg = $this->latestRegistrationSub();

        $admission = DB::table('admissions as adm')
            ->join('students as s', 's.id', '=', 'adm.student_id')
            ->leftJoin('programs as p', 'p.id', '=', 'adm.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->select([
                'adm.id as admission_id', 'adm.admission_no', 'adm.program_id',
                'adm.academic_year as session', 'adm.semester_no', 'adm.admission_type',
                'adm.student_id', 'adm.application_id',
                's.personal_info->first_name as first_name', 's.personal_info->middle_name as middle_name', 's.personal_info->last_name as last_name',
                'dr.name as reg_name', 'dr.father_name', 'dr.mother_name',
                's.mobile', 's.personal_info->gender as gender', 's.personal_info->category as category',
                'p.short_name as class_name',
            ])
            ->where(function ($w) use ($query) {
                $w->where('adm.admission_no', $query)
                  ->orWhere('s.mobile', $query)
                  ->orWhere('s.aadhar_no', $query)
                  ->orWhere('s.abc_id', $query);
            })
            ->orderByDesc('adm.created_at')
            ->first();

        if (!$admission) return response()->json(['student' => null]);

        [$feeGender, $feeCategory] = $this->feeGenderCategory($admission->gender, $admission->category);

        $totalRequired = \App\Models\FeeStructure::requiredFeeFor(
            $admission->program_id,
            $admission->session,
            array_unique([0, (int) $admission->semester_no]),
            $admission->admission_type,
            $feeGender,
            $feeCategory
        );

        $receipts = DB::table('fee_receipts as fr')
            ->where('fr.student_id', $admission->student_id)
            ->select([
                'fr.id', 'fr.receipt_type as description', 'fr.net_amount as amount', 'fr.status', 'fr.is_verified',
                'fr.receipt_no', 'fr.receipt_date as date', 'fr.created_at',
                DB::raw("'credit' as entry_type"),
                DB::raw('(SELECT name FROM users WHERE id = fr.generated_by LIMIT 1) as created_by'),
            ])
            ->orderBy('fr.created_at')
            ->get();

        $student = [
            'admission_id'       => $admission->admission_id,
            'admission_no'       => $admission->admission_no,
            'session'            => $admission->session,
            'semester_no'        => $admission->semester_no,
            'fee_status'         => \App\Support\Payments::admissionFeeStatus((int) $admission->admission_id),
            'student_id'         => $admission->student_id,
            'name'               => $this->composeName($admission->reg_name, $admission->first_name, $admission->middle_name, $admission->last_name),
            'father_name'        => $admission->father_name,
            'mother_name'        => $admission->mother_name,
            'mobile'             => $admission->mobile,
            'gender'             => $admission->gender,
            'category'           => $admission->category,
            'class_name'         => $admission->class_name,
            'total_required_fee' => (float) $totalRequired,
        ];

        return response()->json([
            'student' => $student,
            'ledger'  => $receipts,
        ]);
    }

    // ─── Financial Summary: GET /fees/summary ────────────────────────────────────
    public function summaryIndex(Request $request): JsonResponse
    {
        $session  = $request->session ?? \App\Support\AcademicSession::current();
        $progId   = $request->class_id; // frontend field name kept as class_id; means program_id now

        $admQuery = DB::table('admissions as adm')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId));

        // fee_structures no longer has row-level gender/category to filter
        // in SQL — every particular's full breakdown lives inside one
        // configuration row's amount_json. So: pull every admission's own
        // program/semester/admission-type/gender/category, bulk-fetch the
        // matching amount_json blobs once (requiredFeeBlobMap), and sum each
        // admission's own required fee in PHP.
        $admissionsForFee = DB::table('admissions as adm')
            ->join('students as s', 's.id', '=', 'adm.student_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->select('adm.id', 'adm.program_id', 'adm.semester_no', 'adm.admission_type', 's.personal_info->gender as gender', 's.personal_info->category as category')
            ->get();

        $feeBlobMap = \App\Models\FeeStructure::requiredFeeBlobMap($session, $progId ?: null);

        $requiredFeeByAdmission = $admissionsForFee->mapWithKeys(function ($a) use ($feeBlobMap) {
            [$g, $c] = $this->feeGenderCategory($a->gender, $a->category);
            // Category is part of the blob-map key now (fee_structures is
            // one row per course+category — see the amount_json migration
            // header), gender picks the key inside each matched blob.
            $key = $a->program_id . '|' . $a->semester_no . '|' . $a->admission_type . '|' . $c;
            $blobs = $feeBlobMap[$key] ?? [];
            return [$a->id => \App\Models\FeeStructure::sumFromGenderBlobs($blobs, $g)];
        });

        $totalRequired = $requiredFeeByAdmission->sum();

        $totalCollected = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->where('fr.is_verified', true)
            ->sum('fr.net_amount');

        $totalStudents = (clone $admQuery)->count();

        // adm.fee_status is gone (Phase 4) — paid via the application's own
        // fee or any paid money tagged to the admission directly (misc),
        // same as App\Support\Payments::admissionFeeStatus(). 'Partial' was
        // never actually written anywhere (syncAdmission only ever set
        // Paid/Pending) — kept here as a permanent 0 for the response shape.
        $admissionPaidExists = "EXISTS (
                SELECT 1 FROM payments p
                WHERE (p.student_application_id = adm.application_id AND p.purpose IN ('admission', 'semester_upgrade', 'back_paper') AND p.status = 'paid')
                   OR (p.admission_id = adm.id AND p.status = 'paid')
            )";
        $statusBreakdown = (clone $admQuery)
            ->selectRaw("
                SUM(CASE WHEN {$admissionPaidExists} THEN 1 ELSE 0 END) as fee_paid,
                0 as fee_partial,
                SUM(CASE WHEN NOT {$admissionPaidExists} THEN 1 ELSE 0 END) as fee_pending
            ")->first();

        $summary = [
            'total_students'    => $totalStudents,
            'total_required'    => (float) $totalRequired,
            'total_collected'   => (float) $totalCollected,
            'total_outstanding' => max(0, (float) $totalRequired - (float) $totalCollected),
            'fee_paid'          => $statusBreakdown->fee_paid ?? 0,
            'fee_partial'       => $statusBreakdown->fee_partial ?? 0,
            'fee_pending'       => $statusBreakdown->fee_pending ?? 0,
        ];

        $byFeeType = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->selectRaw("
                fr.receipt_type as fee_type,
                SUM(CASE WHEN fr.is_verified THEN fr.net_amount ELSE 0 END) as collected,
                SUM(CASE WHEN NOT fr.is_verified THEN fr.net_amount ELSE 0 END) as pending,
                COUNT(*) as count
            ")
            ->groupBy('fr.receipt_type')
            ->orderByDesc('collected')
            ->get();

        // Same reasoning as $totalRequired above: no row-level gender/
        // category to join on any more, so class/semester breakdowns are
        // built from $admissionsForFee's already-computed per-admission
        // required fee (PHP-side), joined with SQL-computed collected sums
        // (fee_receipts isn't affected by the fee_structures redesign, so
        // that half stays a plain SQL aggregate).
        $collectedByProgram = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->where('fr.is_verified', true)
            ->selectRaw('adm.program_id, SUM(fr.net_amount) as collected')
            ->groupBy('adm.program_id')
            ->pluck('collected', 'program_id');

        $collectedBySemester = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->where('fr.is_verified', true)
            ->selectRaw('adm.semester_no, SUM(fr.net_amount) as collected')
            ->groupBy('adm.semester_no')
            ->pluck('collected', 'semester_no');

        $programNames = DB::table('programs')->pluck('short_name', 'id');

        $requiredByProgram = [];
        $requiredBySemester = [];
        $studentsBySemester = [];
        foreach ($admissionsForFee as $a) {
            $fee = $requiredFeeByAdmission[$a->id] ?? 0;
            $requiredByProgram[$a->program_id] = ($requiredByProgram[$a->program_id] ?? 0) + $fee;
            $requiredBySemester[$a->semester_no] = ($requiredBySemester[$a->semester_no] ?? 0) + $fee;
            $studentsBySemester[$a->semester_no] = ($studentsBySemester[$a->semester_no] ?? 0) + 1;
        }

        $byClass = collect($requiredByProgram)
            ->map(function ($required, $programId) use ($programNames, $collectedByProgram) {
                $collected = (float) ($collectedByProgram[$programId] ?? 0);
                return (object) [
                    'class_name'  => $programNames[$programId] ?? "Program #{$programId}",
                    'required'    => (float) $required,
                    'collected'   => $collected,
                    'outstanding' => max(0, (float) $required - $collected),
                ];
            })
            ->sortBy('class_name')
            ->values();

        $bySemester = collect($requiredBySemester)
            ->map(function ($required, $semNo) use ($collectedBySemester, $studentsBySemester) {
                $collected = (float) ($collectedBySemester[$semNo] ?? 0);
                return (object) [
                    'semester_no' => (int) $semNo,
                    'students'    => $studentsBySemester[$semNo] ?? 0,
                    'required'    => (float) $required,
                    'collected'   => $collected,
                ];
            })
            ->sortBy('semester_no')
            ->values();

        $latestReg = $this->latestRegistrationSub();
        $recentReceipts = DB::table('fee_receipts as fr')
            ->join('admissions as adm', 'adm.id', '=', 'fr.admission_id')
            ->join('students as s', 's.id', '=', 'adm.student_id')
            ->join('programs as p', 'p.id', '=', 'adm.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->where('adm.academic_year', $session)
            ->when($progId, fn ($q) => $q->where('adm.program_id', $progId))
            ->where('fr.is_verified', true)
            ->select(
                'fr.id', 'fr.receipt_type as fee_type', 'fr.net_amount as amount', 'fr.transaction_id as utr_no', 'fr.created_at',
                's.personal_info->first_name as first_name', 's.personal_info->middle_name as middle_name', 's.personal_info->last_name as last_name', 'dr.name as reg_name',
                'p.short_name as class_name', 'adm.semester_no',
                DB::raw('(SELECT name FROM users WHERE id=fr.generated_by LIMIT 1) as issued_by')
            )
            ->orderByDesc('fr.created_at')
            ->limit(20)
            ->get()
            ->map(function ($r) {
                $r->student_name = $this->composeName($r->reg_name, $r->first_name, $r->middle_name, $r->last_name);
                return $r;
            });

        return response()->json([
            'summary'         => $summary,
            'by_fee_type'     => $byFeeType,
            'by_class'        => $byClass,
            'by_semester'     => $bySemester,
            'recent_receipts' => $recentReceipts,
            'classes'         => DB::table('programs')->where('is_active', true)->whereNull('deleted_at')->select('id', 'short_name as name')->orderBy('short_name')->get(),
        ]);
    }
}
