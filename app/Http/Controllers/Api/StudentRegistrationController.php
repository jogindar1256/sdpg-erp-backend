<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;   // pure-PHP PDF (no wkhtmltopdf binary needed)
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;


class StudentRegistrationController extends Controller
{
    /** Registration fee by level, in rupees. */
    private const FEES = ['UG' => 300, 'PG' => 500, 'BED' => 600];

    // ──────────────────────────────────────────────────────────────────────
    // 1. INIT — save the form into direct_registrations (payment pending)
    // ──────────────────────────────────────────────────────────────────────
    public function initiate(Request $req): JsonResponse
    {
        $v = Validator::make($req->all(), [
            'reg_type'    => 'required|in:UG,PG,BED',
            'program_id'  => 'nullable|exists:programs,id',
            'name'        => 'required|string|max:200',
            'father_name' => 'required|string|max:200',
            'mother_name' => 'required|string|max:200',
            'mobile'      => 'required|digits:10',
            'email'       => 'required|email|max:100',
            'aadhar_no'   => 'required|digits:12',
            'session'     => 'required|string|max:12',  // e.g. 2025-2026
            'ddurn_no'    => 'required|string|max:50',
            'abc_id'      => 'required|string|max:50',
            'family_id'   => 'required|string|max:50',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        // ── Require mobile + email OTP verification BEFORE anything is created.
        // Nothing is drafted, no registration number is generated, and no email
        // is sent until both are verified (cache flags set by the pre-OTP endpoints).
        if (!Cache::get("pre_phone_verified_{$req->mobile}") || !Cache::get("pre_email_verified_{$req->email}")) {
            return response()->json([
                'message' => 'Please verify your mobile and email with OTP before submitting.',
                'errors'  => ['otp' => ['Mobile and email must be verified first.']],
            ], 422);
        }

        $regType = strtoupper($req->reg_type);

        // Same mobile / aadhar / abc_id CAN register for a different course
        // (UG, PG, BEd are separate reg_type values — not a duplicate). But
        // re-registering for the SAME session + course with any of those
        // three identifiers is only allowed if the earlier registration was
        // cancelled by the college — otherwise it's a duplicate entry.
        $dup = DB::table('direct_registrations')
            ->where('session_year', $req->session)
            ->where('reg_type', $regType)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($req) {
                $q->where('mobile', $req->mobile)
                  ->orWhere('aadhar_no', $req->aadhar_no)
                  ->orWhere('abc_id', $req->abc_id);
            })
            ->first();

        if ($dup) {
            return response()->json([
                'message'         => 'A registration already exists for this mobile / Aadhar / ABC ID for this session and course. It must be cancelled by the college office before you can register again for the same course.',
                'registration_id' => $dup->id,
            ], 409);
        }

        $org   = DB::table('organizations')->where('is_active', true)->first();
        $orgId = $org->id ?? null;

        $regNo    = $this->generateRegNo($req->session, $req->program_id);
        $password = strtoupper(Str::random(3)) . rand(100, 999) . Str::lower(Str::random(2));

        try {
            $id = DB::table('direct_registrations')->insertGetId([
                'organization_id'     => $orgId,
                'program_id'          => $req->program_id,
                'registration_no'     => $regNo,
                'reg_type'            => $regType,
                'session_year'        => $req->session,
                'reg_date'            => now()->toDateString(),

                'ddurn_no'            => $req->ddurn_no,
                'abc_id'              => $req->abc_id,
                'family_id'           => $req->family_id,

                'major_subject_1'     => $req->major_subject_1 ?: null,
                'major_subject_2'     => $req->major_subject_2 ?: null,
                'major_subject_3'     => $req->major_subject_3 ?: null,
                'minor_subject_1'     => $req->minor_subject_1 ?: null,
                'subject_id'          => $req->subject_id ?: null,
                'course_group'        => $req->course_group,

                'name'                => $req->name,
                'name_hindi'          => $req->name_hindi,
                'id_proof_type'       => $req->id_proof_type,
                'id_proof_no'         => $req->id_proof_no,
                'father_name'         => $req->father_name,
                'father_name_hindi'   => $req->father_name_hindi,
                'mother_name'         => $req->mother_name,
                'mother_name_hindi'   => $req->mother_name_hindi,
                'dob'                 => $req->dob,
                'gender'              => $req->gender,
                'domestic_state'      => $req->domestic_state,

                'category'            => $req->category,
                'admission_category'  => $req->admission_category,
                'religion'            => $req->religion,
                'nationality'         => $req->nationality ?: 'Indian',

                'caste_cert_no'       => $req->caste_cert_no,
                'eligibility_class'   => $req->eligibility_class,
                'is_divyang'          => $req->is_divyang ?: 'No',
                'caste_cert_date'     => $req->caste_cert_date ?: null,
                'passing_year'        => $req->passing_year,
                'aadhar_no'           => $req->aadhar_no,
                'caste_cert_state'    => $req->caste_cert_state,
                'eligibility_roll_no' => $req->eligibility_roll_no,

                'email'               => $req->email,
                'mobile'              => $req->mobile,

                'ug_university'       => $req->ug_university,
                'ug_institute'        => $req->ug_institute,
                'ug_session'          => $req->ug_session,
                'ug_roll_no'          => $req->ug_roll_no,

                'stream'              => $req->stream,
                'entrance_session'    => $req->entrance_session,
                'entrance_roll_no'    => $req->entrance_roll_no,
                'state_rank'          => $req->state_rank,
                'category_rank'       => $req->category_rank,
                'cut_off'             => $req->cut_off,

                'fee_amount'          => $this->resolveRegistrationFee($req->program_id ? (int) $req->program_id : null, $req->session, $req->gender, $req->category, $regType),
                'payment_status'      => 'pending',
                'status'              => 'pending',

                'temp_password'       => Hash::make($password),
                'temp_password_plain' => $password,

                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Self-registration init failed: ' . $e->getMessage());
            return response()->json(['message' => 'Registration failed. Please try again.'], 500);
        }

        // OTP already verified — create the login account and email the
        // credentials now (the applicant proceeds to pay the fee next).
        $reg = $this->findReg($id);
        $this->provisionAccount($reg, paid: false);

        // Verification is consumed — clear the pre-OTP flags.
        Cache::forget("pre_phone_verified_{$req->mobile}");
        Cache::forget("pre_email_verified_{$req->email}");

        return response()->json([
            'registration_id' => $id,
            'reg_no'          => $regNo,
            'fee'             => $reg->fee_amount,
            'message'         => 'Registration saved. Login details have been emailed. Proceed to pay the fee.',
        ], 201);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1b. PRE-REGISTRATION OTP (keyed by mobile/email, before any draft exists)
    //     Used in step 1 so nothing is created until both are verified.
    // ──────────────────────────────────────────────────────────────────────
    public function preSendPhoneOtp(Request $req): JsonResponse
    {
        $req->validate(['mobile' => 'required|digits:10']);
        $otp = rand(100000, 999999);
        Cache::put("pre_phone_otp_{$req->mobile}", $otp, now()->addMinutes(10));

        $sent = app(\App\Services\SmsService::class)->sendOtp($req->mobile, $otp, null);
        if (!$sent) {
            Log::info("PRE PHONE OTP for {$req->mobile}: {$otp}");
        }

        $resp = ['message' => 'OTP sent to your mobile.'];
        if (config('app.debug')) $resp['debug_otp'] = $otp;
        return response()->json($resp);
    }

    public function preVerifyPhoneOtp(Request $req): JsonResponse
    {
        $req->validate(['mobile' => 'required|digits:10', 'otp' => 'required|digits:6']);
        $cached = Cache::get("pre_phone_otp_{$req->mobile}");
        if (!$cached || (string) $cached !== (string) $req->otp) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }
        Cache::put("pre_phone_verified_{$req->mobile}", true, now()->addMinutes(30));
        Cache::forget("pre_phone_otp_{$req->mobile}");
        return response()->json(['message' => 'Mobile verified.']);
    }

    public function preSendEmailOtp(Request $req): JsonResponse
    {
        $req->validate(['email' => 'required|email']);
        $otp = rand(100000, 999999);
        Cache::put("pre_email_otp_{$req->email}", $otp, now()->addMinutes(10));

        try {
            Mail::raw(
                "Your SDPG College email verification OTP is: {$otp}\n\nValid for 10 minutes. Do not share it with anyone.",
                fn ($m) => $m->to($req->email)->subject('SDPG College — Email Verification OTP')
            );
        } catch (\Throwable $e) {
            Log::error('Pre email OTP failed: ' . $e->getMessage());
        }

        $resp = ['message' => 'OTP sent to your email.'];
        if (config('app.debug')) $resp['debug_otp'] = $otp;
        return response()->json($resp);
    }

    public function preVerifyEmailOtp(Request $req): JsonResponse
    {
        $req->validate(['email' => 'required|email', 'otp' => 'required|digits:6']);
        $cached = Cache::get("pre_email_otp_{$req->email}");
        if (!$cached || (string) $cached !== (string) $req->otp) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }
        Cache::put("pre_email_verified_{$req->email}", true, now()->addMinutes(30));
        Cache::forget("pre_email_otp_{$req->email}");
        return response()->json(['message' => 'Email verified.']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1c. EDIT DRAFT — view + update a registration while it is still unpaid.
    // ──────────────────────────────────────────────────────────────────────
    public function showDraft(Request $req, int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);
        return response()->json(['data' => $reg]);
    }

    // STUDENT edit — allowed ONLY while the registration fee is unpaid.
    public function updateDraft(Request $req, int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);

        if ($reg->payment_status === 'paid') {
            return response()->json(['message' => 'Registration is locked after payment. Please contact the college office to make changes.'], 409);
        }

        $this->applyDraftUpdate($id, $req);
        return response()->json(['message' => 'Registration details updated.']);
    }

    // COLLEGE edit — office staff may modify details at any time, including
    // AFTER payment (used by the "Modify" button on /college/registration/status).
    // Sits behind the authenticated college portal middleware.
    public function adminUpdateDraft(Request $req, int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);

        $this->applyDraftUpdate($id, $req);
        return response()->json(['message' => 'Registration details updated by office.']);
    }

    /** Shared: write the editable applicant-detail columns onto a registration. */
    private function applyDraftUpdate(int $id, Request $req): void
    {
        $fields = [
            'name', 'name_hindi', 'father_name', 'father_name_hindi', 'mother_name', 'mother_name_hindi',
            'dob', 'gender', 'id_proof_type', 'id_proof_no', 'aadhar_no', 'abc_id', 'ddurn_no', 'family_id',
            'domestic_state', 'category', 'admission_category', 'religion', 'nationality',
            'caste_cert_no', 'caste_cert_date', 'caste_cert_state', 'passing_year', 'is_divyang',
            'eligibility_class', 'eligibility_roll_no', 'course_group',
            'major_subject_1', 'major_subject_2', 'major_subject_3', 'minor_subject_1', 'subject_id',
            'ug_university', 'ug_institute', 'ug_session', 'ug_roll_no',
            'stream', 'entrance_session', 'entrance_roll_no', 'state_rank', 'category_rank', 'cut_off',
        ];
        $update = [];
        foreach ($fields as $f) {
            if ($req->has($f)) $update[$f] = $req->input($f) ?: null;
        }
        $update['updated_at'] = now();

        DB::table('direct_registrations')->where('id', $id)->update($update);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. PHONE OTP
    // ──────────────────────────────────────────────────────────────────────
    public function sendPhoneOtp(Request $req): JsonResponse
    {
        $id  = $this->regId($req);
        $reg = $this->findReg($id);
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);

        $otp = rand(100000, 999999);
        Cache::put("reg_phone_otp_{$reg->id}", $otp, now()->addMinutes(10));

        // Send via the Infibrix SMS gateway. When disabled/misconfigured the
        // service returns false — fall back to logging so dev/testing works.
        $sent = app(\App\Services\SmsService::class)->sendOtp($reg->mobile, $otp, $reg);
        if (!$sent) {
            Log::info("PHONE OTP for registration {$reg->id}: {$otp}");
        }

        $masked   = substr($reg->mobile, 0, 2) . 'XXXXXX' . substr($reg->mobile, -2);
        $response = ['message' => "OTP sent to {$masked}."];
        if (config('app.debug')) $response['debug_otp'] = $otp;   // only in local/dev

        return response()->json($response);
    }

    public function verifyPhoneOtp(Request $req): JsonResponse
    {
        $id  = $this->regId($req);
        $reg = $this->findReg($id);
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);

        $req->validate(['otp' => 'required|digits:6']);
        $cached = Cache::get("reg_phone_otp_{$reg->id}");

        if (!$cached || (string) $cached !== (string) $req->otp) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        DB::table('direct_registrations')->where('id', $reg->id)
            ->update(['phone_verified' => true, 'updated_at' => now()]);
        Cache::forget("reg_phone_otp_{$reg->id}");

        return response()->json(['message' => 'Mobile verified.']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. EMAIL OTP
    // ──────────────────────────────────────────────────────────────────────
    public function sendEmailOtp(Request $req): JsonResponse
    {
        $id  = $this->regId($req);
        $reg = $this->findReg($id);
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);

        $otp = rand(100000, 999999);
        Cache::put("reg_email_otp_{$reg->id}", $otp, now()->addMinutes(10));

        try {
            Mail::raw(
                "Your SDPG College email verification OTP is: {$otp}\n\n"
                . "Valid for 10 minutes. Do not share it with anyone.",
                fn ($m) => $m->to($reg->email)->subject('SDPG College — Email Verification OTP')
            );
        } catch (\Throwable $e) {
            Log::error('Email OTP send failed: ' . $e->getMessage());
            Log::info("EMAIL OTP fallback for registration {$reg->id}: {$otp}");
        }

        $response = ['message' => 'OTP sent to your email.'];
        if (config('app.debug')) $response['debug_otp'] = $otp;

        return response()->json($response);
    }

    public function verifyEmailOtp(Request $req): JsonResponse
    {
        $id  = $this->regId($req);
        $reg = $this->findReg($id);
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);

        $req->validate(['otp' => 'required|digits:6']);
        $cached = Cache::get("reg_email_otp_{$reg->id}");

        if (!$cached || (string) $cached !== (string) $req->otp) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        DB::table('direct_registrations')->where('id', $reg->id)
            ->update(['email_verified' => true, 'updated_at' => now()]);
        Cache::forget("reg_email_otp_{$reg->id}");

        return response()->json(['message' => 'Email verified.']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. PAYMENT INITIATE — create a Razorpay order
    // ──────────────────────────────────────────────────────────────────────
    public function initiatePayment(Request $req): JsonResponse
    {
        $reg = $this->findReg($this->regId($req));
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);

        if (!$reg->phone_verified || !$reg->email_verified) {
            return response()->json(['message' => 'Verify mobile and email before paying.'], 422);
        }
        if ($reg->payment_status === 'paid') {
            return response()->json(['message' => 'Registration fee already paid.'], 409);
        }

        $rupees = (int) ($reg->fee_amount ?: (self::FEES[$reg->reg_type] ?? 0));
        $amount = $rupees * 100; // paise

        $key    = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');
        if (!$key || !$secret) {
            return response()->json(['message' => 'Payment gateway not configured. Contact the office.'], 503);
        }

        try {
            $resp = Http::withBasicAuth($key, $secret)
                ->asJson()
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount'          => $amount,
                    'currency'        => 'INR',
                    'receipt'         => 'REG_' . $reg->registration_no,
                    'payment_capture' => 1,
                    'notes'           => [
                        'registration_id' => (string) $reg->id,
                        'reg_no'          => $reg->registration_no,
                        'name'            => $reg->name,
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('Razorpay order request failed: ' . $e->getMessage());
            return response()->json(['message' => 'Could not reach the payment gateway.'], 502);
        }

        if ($resp->failed()) {
            Log::error('Razorpay order error: ' . $resp->body());
            return response()->json(['message' => 'Could not create the payment order.'], 502);
        }

        $order = $resp->json();

        DB::table('direct_registrations')->where('id', $reg->id)->update([
            'razorpay_order_id' => $order['id'],
            'updated_at'        => now(),
        ]);

        return response()->json([
            'order_id'        => $order['id'],
            'amount'          => $amount,        // paise (Razorpay checkout expects paise)
            'amount_rupees'   => $rupees,
            'currency'        => 'INR',
            'key'             => $key,
            'name'            => $reg->name,
            'email'           => $reg->email,
            'mobile'          => $reg->mobile,
            'reg_no'          => $reg->registration_no,
            'registration_id' => $reg->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. PAYMENT VERIFY — HMAC check → mark paid, create login, email, receipt
    // ──────────────────────────────────────────────────────────────────────
    public function verifyPayment(Request $req): JsonResponse
    {
        $reg = $this->findReg($this->regId($req));
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);

        $req->validate([
            'razorpay_order_id'   => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature'  => 'required|string',
        ]);

        $secret   = config('services.razorpay.secret');
        $expected = hash_hmac('sha256', $req->razorpay_order_id . '|' . $req->razorpay_payment_id, (string) $secret);

        if (!$secret || !hash_equals($expected, $req->razorpay_signature)) {
            // Treat as a failed payment: still create login + email so the
            // applicant can retry from the portal.
            $this->markFailed($reg);
            return response()->json(['message' => 'Payment could not be verified. Registration saved as incomplete.'], 422);
        }

        $receiptNo = 'RCPT' . date('y') . str_pad($reg->id, 6, '0', STR_PAD_LEFT);

        DB::table('direct_registrations')->where('id', $reg->id)->update([
            'payment_status'      => 'paid',
            'status'              => 'registered',
            'razorpay_payment_id' => $req->razorpay_payment_id,
            'paid_at'             => now(),
            'receipt_no'          => $receiptNo,
            'updated_at'          => now(),
        ]);

        $reg = $this->findReg($reg->id); // refresh
        $this->provisionAccount($reg, paid: true);

        return response()->json([
            'message'         => 'Payment successful. Login credentials have been emailed to you.',
            'registration_id' => $reg->id,
            'reg_no'          => $reg->registration_no,
            'receipt_no'      => $receiptNo,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. PAYMENT FAILED — client-reported dismissal / failure
    // ──────────────────────────────────────────────────────────────────────
    public function paymentFailed(Request $req): JsonResponse
    {
        $reg = $this->findReg($this->regId($req));
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);

        if ($reg->payment_status === 'paid') {
            return response()->json(['message' => 'Payment already completed.'], 409);
        }

        $this->markFailed($reg);

        return response()->json([
            'message'         => 'Registration saved but not paid. Login credentials emailed. Pay the fee from the student portal to complete it.',
            'registration_id' => $reg->id,
            'reg_no'          => $reg->registration_no,
        ]);
    }

    private function markFailed(object $reg): void
    {
        DB::table('direct_registrations')->where('id', $reg->id)->update([
            'payment_status' => 'failed',
            'status'         => 'incomplete',
            'updated_at'     => now(),
        ]);

        $reg = $this->findReg($reg->id);
        $this->provisionAccount($reg, paid: false);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. RECEIPT — stream the registration receipt PDF
    // ──────────────────────────────────────────────────────────────────────
    public function receipt(int $id)
    {
        $reg = $this->findReg($id);
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);
        if ($reg->payment_status !== 'paid') {
            return response()->json(['message' => 'Receipt is available only after successful payment.'], 422);
        }

        $path = $reg->pdf_path;
        if (!$path || !Storage::exists($path)) {
            $path = $this->generateReceiptPdf($reg); // try to (re)generate
        }

        if ($path && Storage::exists($path)) {
            return response()->streamDownload(
                fn () => print(Storage::get($path)),
                "Registration-Receipt-{$reg->registration_no}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        }

        // PDF engine unavailable — return JSON so the client can print its own copy.
        return response()->json([
            'message' => 'PDF unavailable on server; use the on-screen receipt.',
            'data'    => $this->receiptData($reg),
        ], 200);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. STUDENT PORTAL — pending registration + pay
    // ──────────────────────────────────────────────────────────────────────
    public function studentPending(Request $req): JsonResponse
    {
        $user = $req->user();

        $reg = DB::table('direct_registrations')
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('mobile', $user->mobile);
            })
            ->where('payment_status', '!=', 'paid')
            ->orderByDesc('id')
            ->first();

        if (!$reg) return response()->json(['data' => null]);

        return response()->json(['data' => [
            'registration_id' => $reg->id,
            'reg_no'          => $reg->registration_no,
            'reg_type'        => $reg->reg_type,
            'session'         => $reg->session_year,
            'fee'             => (float) $reg->fee_amount,
            'payment_status'  => $reg->payment_status,
            'status'          => $reg->status,
        ]]);
    }

    /** Re-initiate payment for the logged-in student's own pending registration. */
    public function payPending(Request $req): JsonResponse
    {
        $user = $req->user();
        $reg  = $this->findReg($this->regId($req));

        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);
        if ($reg->user_id && (int) $reg->user_id !== (int) $user->id && $reg->mobile !== $user->mobile) {
            return response()->json(['message' => 'This registration does not belong to you.'], 403);
        }

        // OTP was already verified during sign-up; force the flags so initiate passes.
        $req->merge(['registration_id' => $reg->id]);
        if (!$reg->phone_verified || !$reg->email_verified) {
            DB::table('direct_registrations')->where('id', $reg->id)
                ->update(['phone_verified' => true, 'email_verified' => true]);
        }

        return $this->initiatePayment($req);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8a. LOGIN LOOKUP — resolve name + father name from a registration no.
    //     Public (pre-login). Returns a masked mobile hint, never the full no.
    // ──────────────────────────────────────────────────────────────────────
    public function loginLookup(Request $req): JsonResponse
    {
        $regNo = trim((string) $req->query('registration_no'));
        if ($regNo === '') {
            return response()->json(['found' => false, 'message' => 'Enter your registration number.']);
        }

        $reg = DB::table('direct_registrations')
            ->where('registration_no', $regNo)
            ->whereNull('deleted_at')
            ->first();

        if (!$reg) {
            return response()->json(['found' => false, 'message' => 'No registration found for this number.']);
        }

        $mobile = (string) ($reg->mobile ?? '');

        return response()->json([
            'found'       => true,
            'name'        => $reg->name,
            'father_name' => $reg->father_name,
            // Hint only — helps the student recall which mobile is their User ID.
            'mobile_hint' => strlen($mobile) === 10 ? substr($mobile, 0, 2) . 'XXXXXX' . substr($mobile, -2) : null,
            'paid'        => $reg->payment_status === 'paid',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8b. PUBLIC CATALOG — courses + subjects for the registration form
    //     (the form is public, so these must NOT sit behind auth)
    // ──────────────────────────────────────────────────────────────────────
    public function publicCourses(Request $req): JsonResponse
    {
        // Frontend sends UG | PG | BED; DB enum is UG | PG | BEd.
        $map     = ['UG' => 'UG', 'PG' => 'PG', 'BED' => 'BEd', 'BEED' => 'BEd'];
        $level   = strtoupper((string) $req->query('level', ''));
        $dbLevel = $map[$level] ?? $level;

        $org = DB::table('organizations')->where('is_active', true)->first();

        $rows = DB::table('programs')
            ->where('is_active', true)
            ->when($org, fn ($q) => $q->where('organization_id', $org->id))
            ->when($dbLevel, fn ($q) => $q->where('level', $dbLevel))
            ->orderBy('name')
            ->get(['id', 'name', 'short_name', 'code', 'level', 'total_semesters']);

        $data = $rows->map(fn ($p) => [
            'id'         => $p->id,
            'short_name' => $p->short_name,
            'full_name'  => $p->name,           // alias so the dropdown shows the long name
            'name'       => $p->name,
            'code'       => $p->code,
            'level'      => $p->level,
            // crude flag so the form can show the "Group" dropdown for B.Sc only
            'has_group'  => stripos($p->short_name . ' ' . $p->name, 'sc') !== false,
        ]);

        return response()->json($data->values());
    }

    public function publicSubjects(Request $req, $programId): JsonResponse
    {
        $semester = (int) $req->query('semester', 0);

        $base = DB::table('subjects')
            ->where('program_id', $programId)
            ->where('is_active', true)
            ->select('id', 'name', 'code', 'type', 'semester_no')
            ->orderBy('type')->orderBy('name');

        $subjects = (clone $base)->when($semester, fn ($q) => $q->where('semester_no', $semester))->get();

        // Fallback: if nothing matched the requested semester (e.g. annual courses),
        // return every active subject for the program so the dropdowns still fill.
        if ($subjects->isEmpty()) {
            $subjects = $base->get();
        }

        return response()->json([
            'core'     => $subjects->whereIn('type', ['compulsory', 'practical'])->values(),
            'optional' => $subjects->whereIn('type', ['optional', 'elective', 'project'])->values(),
            'all'      => $subjects->values(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8c. FETCH OLD RECORD — autofill from a prior DDU student (UG/PG)
    //     Used by the "Fetch Record" button on PG / B.Ed forms.
    //     GET /student/register/fetch-record?roll_no=...
    // ──────────────────────────────────────────────────────────────────────
    public function fetchOldRecord(Request $req): JsonResponse
    {
        $roll = trim((string) ($req->query('roll_no') ?? $req->query('ug_roll_no') ?? ''));

        if (strlen($roll) < 3) {
            return response()->json(['found' => false, 'message' => 'Enter a valid roll number.']);
        }

        // 1) Master record: a previously admitted DDU student.
        $student = DB::table('students')
            ->where(function ($q) use ($roll) {
                $q->where('university_roll_no', $roll)
                  ->orWhere('enrollment_no', $roll);
            })
            ->whereNull('deleted_at')
            ->first();

        if (!$student) {
            return response()->json([
                'found'   => false,
                'message' => 'No old record found for this roll number. Please fill the form manually.',
            ]);
        }

        // 2) Enrich from the most recent PAID self-registration of the same person
        //    (this is where father/mother/Hindi names/ID proof/caste live).
        $prior = DB::table('direct_registrations')
            ->where('student_id', $student->id)
            ->where('payment_status', 'paid')
            ->orderByDesc('id')
            ->first();

        $genderMap = ['male' => 'Male', 'female' => 'Female', 'other' => 'Trans', 'trans' => 'Trans'];

        $data = [
            'name'              => trim("{$student->first_name} " . ($student->middle_name ?? '') . " {$student->last_name}"),
            'gender'            => $genderMap[strtolower((string) $student->gender)] ?? null,
            'dob'               => $student->date_of_birth,
            'category'          => $student->category ? strtoupper($student->category) : null,
            'religion'          => $student->religion,
            'nationality'       => $student->nationality ?: 'Indian',
            'aadhar_no'         => $student->aadhar_no,
            'abc_id'            => $student->abc_id,
            'mobile'            => $student->mobile,
            'email'             => $student->email,
            'domestic_state'    => $student->permanent_state,

            // From prior self-registration if available (students table doesn't hold these)
            'name_hindi'         => $prior->name_hindi         ?? null,
            'father_name'        => $prior->father_name        ?? null,
            'father_name_hindi'  => $prior->father_name_hindi  ?? null,
            'mother_name'        => $prior->mother_name        ?? null,
            'mother_name_hindi'  => $prior->mother_name_hindi  ?? null,
            'id_proof_type'      => $prior->id_proof_type      ?? null,
            'id_proof_no'        => $prior->id_proof_no        ?? null,
            'caste_cert_no'      => $prior->caste_cert_no      ?? null,
            'caste_cert_date'    => $prior->caste_cert_date    ?? null,
            'caste_cert_state'   => $prior->caste_cert_state   ?? null,
            'ddurn_no'           => $prior->ddurn_no           ?? null,
            'family_id'          => $prior->family_id          ?? null,
        ];

        return response()->json([
            'found'   => true,
            'source'  => $prior ? 'students+prior_registration' : 'students',
            'data'    => array_filter($data, fn ($v) => $v !== null && $v !== ''),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 9. COLLEGE — self-registered list (paid / not-paid status)
    // ──────────────────────────────────────────────────────────────────────
    public function adminIndex(Request $req): JsonResponse
    {
        $q = DB::table('direct_registrations as dr')
            ->leftJoin('programs as p', 'p.id', 'dr.program_id')
            ->select(
                'dr.id', 'dr.registration_no', 'dr.reg_type', 'dr.session_year',
                'dr.name', 'dr.father_name', 'dr.mobile', 'dr.email',
                'dr.category', 'dr.gender', 'dr.phone_verified', 'dr.email_verified',
                'dr.fee_amount', 'dr.payment_status', 'dr.status', 'dr.paid_at',
                'dr.razorpay_payment_id', 'dr.created_at',
                'p.short_name as program', 'p.full_name as program_name'
            )
            ->when($req->payment_status, fn ($q) => $q->where('dr.payment_status', $req->payment_status))
            ->when($req->reg_type,       fn ($q) => $q->where('dr.reg_type', strtoupper($req->reg_type)))
            ->when($req->session,        fn ($q) => $q->where('dr.session_year', $req->session))
            ->when($req->search,         fn ($q) => $q->where(function ($q2) use ($req) {
                $q2->where('dr.name', 'ilike', "%{$req->search}%")
                   ->orWhere('dr.mobile', 'ilike', "%{$req->search}%")
                   ->orWhere('dr.registration_no', 'ilike', "%{$req->search}%");
            }))
            ->orderByDesc('dr.created_at');

        return response()->json($q->paginate(50));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 10. COLLEGE — cancel a registration.
    //     This is the ONLY way the same mobile/aadhar/abc_id can register
    //     again for the same session + course — the duplicate check in
    //     initiate() blocks re-registration unless the prior one is
    //     cancelled here first.
    // ──────────────────────────────────────────────────────────────────────
    public function cancelRegistration(Request $req, int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);

        if ($reg->status === 'cancelled') {
            return response()->json(['message' => 'Registration is already cancelled.'], 409);
        }

        $req->validate(['reason' => 'nullable|string|max:500']);

        DB::table('direct_registrations')->where('id', $id)->update([
            'status'        => 'cancelled',
            'cancelled_by'  => $req->user()?->id,
            'cancelled_at'  => now(),
            'cancel_reason' => $req->reason,
            'updated_at'    => now(),
        ]);

        return response()->json([
            'message' => 'Registration cancelled. The applicant may now register again for this session and course.',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /** Create login user (+ best-effort student profile), email credentials. */
    private function provisionAccount(object $reg, bool $paid): void
    {
        try {
            $plain = $reg->temp_password_plain ?: (strtoupper(Str::random(3)) . rand(100, 999));

            // Find or create the login user (keyed on mobile, student portal).
            $user = User::where('mobile', $reg->mobile)->where('portal', 'student')->first();

            if (!$user) {
                $user = User::create([
                    'organization_id' => $reg->organization_id,
                    'name'            => $reg->name,
                    'mobile'          => $reg->mobile,
                    'email'           => $reg->email,
                    // User model casts 'password' => 'hashed', so pass PLAIN here
                    // (passing a pre-hashed value would double-hash and break login).
                    'password'        => $plain,
                    'portal'          => 'student',
                    'is_active'       => true,
                ]);
                try { $user->assignRole('student'); } catch (\Throwable $e) { Log::warning('assignRole(student) failed: ' . $e->getMessage()); }
            }

            // Best-effort student profile on successful payment (keeps the portal usable).
            //
            // One person can hold several direct_registrations rows (UG, PG,
            // BEd are separate reg_type values — allowed by design) that all
            // share this same $user (looked up by mobile above). Without a
            // dedup check here, each registration's first paid pass would
            // blindly insert its OWN new students row for the same physical
            // person — fragmenting their identity across multiple `students`
            // records (separate confirmation status, documents, fee history,
            // etc. per program instead of one person with many applications).
            // Reuse an existing students row for this user if one already
            // exists from an earlier registration.
            $studentId = $reg->student_id;
            if ($paid && !$studentId) {
                $existing = DB::table('students')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at')
                    ->orderByDesc('id')
                    ->first();
                $studentId = $existing->id ?? $this->createStudentProfile($reg, $user->id);
            }

            $update = [
                'user_id'    => $user->id,
                'student_id' => $studentId,
                'updated_at' => now(),
            ];
            // Keep the plain password while still unpaid so a later successful
            // retry emails the SAME working password (the account is created here).
            if ($paid) {
                $update['temp_password_plain'] = null;
            }
            DB::table('direct_registrations')->where('id', $reg->id)->update($update);

            // Generate the receipt PDF (only when paid).
            if ($paid) {
                $this->generateReceiptPdf($this->findReg($reg->id));
            }

            $this->sendCredentialsEmail($reg, $plain, $paid);
        } catch (\Throwable $e) {
            Log::error("provisionAccount failed for registration {$reg->id}: " . $e->getMessage());
        }
    }

    private function createStudentProfile(object $reg, int $userId): ?int
    {
        try {
            $parts = preg_split('/\s+/', trim($reg->name));
            $first = array_shift($parts) ?: $reg->name;
            $last  = $parts ? array_pop($parts) : '';
            $middle = $parts ? implode(' ', $parts) : null;

            $genderMap = ['male' => 'male', 'female' => 'female', 'other' => 'other'];
            $gender    = $genderMap[strtolower((string) $reg->gender)] ?? null;

            $catMap = ['general' => 'general', 'gen' => 'general', 'obc' => 'obc', 'sc' => 'sc', 'st' => 'st', 'ews' => 'ews'];
            $category = $catMap[strtolower((string) $reg->category)] ?? null;

            $data = array_filter([
                'organization_id'  => $reg->organization_id,
                'user_id'          => $userId,
                'first_name'       => $first,
                'middle_name'      => $middle,
                'last_name'        => $last ?: $first,
                'gender'           => $gender,
                'date_of_birth'    => $this->safeDate($reg->dob),
                'category'         => $category,
                'religion'         => $reg->religion,
                'nationality'      => $reg->nationality,
                'aadhar_no'        => $reg->aadhar_no,
                'abc_id'           => $reg->abc_id,
                'mobile'           => $reg->mobile,
                'email'            => $reg->email,
                'permanent_state'  => $reg->domestic_state,
                'status'           => 'active',
                // Provisional — this row exists because the application flow's
                // FKs need it, but this is NOT yet "an actual student". It only
                // flips to true once the education fee is paid AND the college
                // accepts the application (ApplicationController::updateStatus).
                'is_confirmed'     => false,
            ], fn ($v) => $v !== null && $v !== '');

            $data['created_at'] = now();
            $data['updated_at'] = now();

            return DB::table('students')->insertGetId($data);
        } catch (\Throwable $e) {
            Log::warning("createStudentProfile skipped for registration {$reg->id}: " . $e->getMessage());
            return null;
        }
    }

    private function generateReceiptPdf(object $reg): ?string
    {
        try {
            $program = $reg->program_id ? DB::table('programs')->find($reg->program_id) : null;
            $org     = $reg->organization_id ? DB::table('organizations')->find($reg->organization_id) : null;

            $pdf  = Pdf::loadView('pdf.registration-receipt', [
                'reg'     => $reg,
                'program' => $program,
                'org'     => $org,
            ])->setPaper('a4');

            $path = "receipts/registrations/{$reg->registration_no}.pdf";
            Storage::put($path, $pdf->output());

            DB::table('direct_registrations')->where('id', $reg->id)
                ->update(['pdf_path' => $path, 'updated_at' => now()]);

            return $path;
        } catch (\Throwable $e) {
            Log::error("Receipt PDF generation failed for registration {$reg->id}: " . $e->getMessage());
            return null;
        }
    }

    private function sendCredentialsEmail(object $reg, string $plainPassword, bool $paid): void
    {
        $program = $reg->program_id ? DB::table('programs')->find($reg->program_id) : null;
        $course  = $program->full_name ?? $program->short_name ?? $reg->reg_type;
        $fee     = (int) ($reg->fee_amount ?: (self::FEES[$reg->reg_type] ?? 0));
        $portal  = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://erp.sdpgcollege.ac.in')), '/');

        $subject = $paid
            ? 'SDPG College — Registration Complete & Login Credentials'
            : 'SDPG College — Registration Saved (Payment Pending)';

        $payLine = $paid
            ? "Registration Fee : Rs. {$fee} — PAID"
            : "Registration Fee : Rs. {$fee} — PENDING (log in and pay to complete your registration)";

        $intro = $paid
            ? "Congratulations! Your registration at SDPG College is complete."
            : "Your registration at SDPG College has been saved, but the fee payment was not confirmed.";

        $body = "Dear {$reg->name},\n\n{$intro}\n\n"
            . "==============================\n"
            . "REGISTRATION DETAILS\n"
            . "==============================\n"
            . "Registration No. : {$reg->registration_no}\n"
            . "Course           : {$course}\n"
            . "Session          : {$reg->session_year}\n"
            . "{$payLine}\n\n"
            . "==============================\n"
            . "LOGIN CREDENTIALS\n"
            . "==============================\n"
            . "Portal   : {$portal}/student/login\n"
            . "Login ID : {$reg->mobile}  (your mobile number)\n"
            . "Password : {$plainPassword}\n\n"
            . ($paid
                ? "Please change your password after your first login.\n\n"
                : "Log in with the above credentials and pay Rs. {$fee} from the student portal to complete your registration.\n\n")
            . "Regards,\nSDPG College ERP\n"
            . "(Do not share your password with anyone.)";

        try {
            Mail::raw($body, fn ($m) => $m->to($reg->email)->subject($subject));
        } catch (\Throwable $e) {
            Log::error("Credentials email failed for registration {$reg->id}: " . $e->getMessage());
            Log::info("CREDENTIALS for {$reg->mobile}: {$plainPassword} (paid=" . ($paid ? '1' : '0') . ')');
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // REGISTRATION SLIP — full applicant details + payment status (paid only).
    // Distinct from the fee receipt: this is the complete registration record.
    // ──────────────────────────────────────────────────────────────────────
    public function registrationSlip(int $id)
    {
        $reg = $this->findReg($id);
        if (!$reg) return response()->json(['message' => 'Registration not found.'], 404);
        if ($reg->payment_status !== 'paid') {
            return response()->json(['message' => 'Registration slip is available only after payment.'], 422);
        }

        $program = $reg->program_id ? DB::table('programs')->find($reg->program_id) : null;
        $org     = $reg->organization_id ? DB::table('organizations')->find($reg->organization_id) : null;

        // Major subjects come from the subjects master.
        $subjIds = array_filter([
            $reg->major_subject_1 ?? null, $reg->major_subject_2 ?? null,
            $reg->major_subject_3 ?? null, $reg->subject_id ?? null,
        ]);
        $names = $subjIds ? DB::table('subjects')->whereIn('id', $subjIds)->pluck('name', 'id')->all() : [];

        // Minor subject is picked from the vocational / co-curricular paper master.
        $minor = null;
        if (!empty($reg->minor_subject_1)) {
            $vp = DB::table('vocational_papers')->find($reg->minor_subject_1);
            $minor = $vp
                ? trim($vp->paper_name . ($vp->paper_code ? " ({$vp->paper_code})" : ''))
                : ($names[$reg->minor_subject_1] ?? null);   // fallback for older rows
        }

        $subjects = array_filter([
            'Major Subject 1' => $names[$reg->major_subject_1] ?? null,
            'Major Subject 2' => $names[$reg->major_subject_2] ?? null,
            'Major Subject 3' => $names[$reg->major_subject_3] ?? null,
            'Minor Subject 1' => $minor,
            'Subject'         => $names[$reg->subject_id] ?? null,
        ]);

        try {
            $pdf = Pdf::loadView('pdf.registration-slip', compact('reg', 'program', 'org', 'subjects'))->setPaper('a4');
            return response()->streamDownload(
                fn () => print($pdf->output()),
                "Registration-Slip-{$reg->registration_no}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            Log::error("Registration slip PDF failed for {$reg->id}: " . $e->getMessage());
            return response()->json(['message' => 'Could not generate the registration slip.'], 500);
        }
    }

    private function receiptData(object $reg): array
    {
        $program = $reg->program_id ? DB::table('programs')->find($reg->program_id) : null;
        return [
            'reg_no'      => $reg->registration_no,
            'receipt_no'  => $reg->receipt_no,
            'name'        => $reg->name,
            'father_name' => $reg->father_name,
            'mobile'      => $reg->mobile,
            'email'       => $reg->email,
            'course'      => $program->full_name ?? $program->short_name ?? $reg->reg_type,
            'session'     => $reg->session_year,
            'fee'         => (float) $reg->fee_amount,
            'payment_id'  => $reg->razorpay_payment_id,
            'paid_at'     => $reg->paid_at,
            'status'      => $reg->status,
        ];
    }

    // ── small utilities ────────────────────────────────────────────────────

    private function regId(Request $req): ?int
    {
        return (int) ($req->registration_id ?? $req->student_id ?? $req->admission_id ?? 0) ?: null;
    }

    private function findReg(?int $id): ?object
    {
        if (!$id) {
            return null;
        }
        return DB::table('direct_registrations')->whereNull('deleted_at')->find($id);
    }

    /**
     * Registration fee comes from Master Settings (`registration_fees`,
     * keyed by program_id + session_year + semester_no + registration_mode,
     * with amounts stored as a JSON map keyed "{gender}_{category}", e.g.
     * "male_gen" — must match the exact key format the settings UI writes:
     * `${gender.toLowerCase()}_${category.toLowerCase()}`).
     *
     * Falls back to the static self::FEES table by level (UG/PG/BED) when
     * no Master Settings row exists for that program/session yet, so
     * registration never hard-fails just because MS hasn't been configured.
     */
    private function resolveRegistrationFee(?int $programId, string $session, ?string $gender, ?string $category, string $regType): int
    {
        if ($programId) {
            $row = DB::table('registration_fees')
                ->where('program_id', $programId)
                ->where('session_year', $session)
                ->where('registration_mode', 'Regular')
                ->orderByDesc('id')
                ->first();

            if ($row && $row->amounts) {
                $amounts = is_string($row->amounts) ? json_decode($row->amounts, true) : (array) $row->amounts;
                $genderKey   = strtolower($gender ?: 'male');
                $categoryKey = strtolower($category ?: 'gen');
                $key = "{$genderKey}_{$categoryKey}";

                if (isset($amounts[$key]) && is_numeric($amounts[$key])) {
                    return (int) $amounts[$key];
                }
                // Fall back to the "gen" category amount for that gender if the
                // specific category key wasn't configured.
                $fallbackKey = "{$genderKey}_gen";
                if (isset($amounts[$fallbackKey]) && is_numeric($amounts[$fallbackKey])) {
                    return (int) $amounts[$fallbackKey];
                }
            }
        }

        return self::FEES[$regType] ?? 0;
    }

    /**
     * Public metadata for the registration landing page: current registration
     * fee (Master-Settings-driven, falls back to static table), the session
     * year to display (latest configured in MS for this program, else the
     * current academic-year fallback), and the admission conditions/
     * eligibility text for the chosen program, if configured.
     */
    public function registrationMeta(Request $req): JsonResponse
    {
        $regType   = strtoupper((string) $req->reg_type);
        $programId = $req->program_id ? (int) $req->program_id : null;

        // Session: prefer the most recent session_year configured in Master
        // Settings for this program (registration_fees or admission_conditions);
        // otherwise fall back to the current academic year (Apr–Mar cycle).
        $session = null;
        if ($programId) {
            $session = DB::table('registration_fees')
                ->where('program_id', $programId)
                ->orderByDesc('session_year')
                ->value('session_year');
        }
        if (!$session) {
            $now = Carbon::now();
            $startYear = $now->month >= 4 ? $now->year : $now->year - 1;
            $session = $startYear . '-' . ($startYear + 1);
        }

        $fee = $this->resolveRegistrationFee($programId, $session, $req->gender, $req->category, $regType);

        $condition = null;
        if ($programId) {
            $condition = DB::table('admission_conditions')
                ->where('program_id', $programId)
                ->where('session_year', $session)
                ->orderByDesc('id')
                ->first();
        }

        return response()->json([
            'session_year'      => $session,
            'fee'                => $fee,
            'admission_condition' => $condition,
        ]);
    }

    private function generateRegNo(string $session, $programId): string
    {
        [$ys, $ye] = $this->sessionYears($session);
        $progCode  = str_pad((string) ($programId ?: 0), 2, '0', STR_PAD_LEFT);
        $seq = DB::table('direct_registrations')
            ->where('session_year', $session)
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->count() + 1;

        return $ys . $ye . $progCode . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function sessionYears(string $session): array
    {
        $parts = explode('-', $session);
        return [
            substr($parts[0] ?? date('Y'), 2, 2),
            substr($parts[1] ?? (string) (date('Y') + 1), 2, 2),
        ];
    }

    private function safeDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
