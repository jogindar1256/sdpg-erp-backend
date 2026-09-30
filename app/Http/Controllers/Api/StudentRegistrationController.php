<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RegistrationInputGuard;
use App\Support\TextNormalizer;
use Barryvdh\DomPDF\Facade\Pdf;
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
            'reg_type' => 'required|in:UG,PG,BED',
            'program_id' => 'nullable|exists:programs,id',
            'name' => 'required|string|max:200',
            'father_name' => 'required|string|max:200',
            'mother_name' => 'required|string|max:200',
            'mobile' => 'required|digits:10',
            'email' => 'required|email|max:100',
            'aadhar_no' => 'required|digits:12',
            'gender' => 'required|in:Male,Female,Transgender',
            'session' => 'required|string|max:12',  // e.g. 2025-2026
            'ddurn_no' => 'required|string|max:50',
            'abc_id' => 'required|string|max:50',
            'family_id' => 'nullable|string|max:50', // optional — not every applicant has one issued yet
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $guardErrors = [];
        foreach (['name', 'father_name', 'mother_name'] as $nameField) {
            if ($msg = RegistrationInputGuard::nameError((string) $req->input($nameField))) {
                $guardErrors[$nameField] = [$msg];
            }
        }
        if ($msg = RegistrationInputGuard::aadhaarError((string) $req->aadhar_no)) {
            $guardErrors['aadhar_no'] = [$msg];
        }
        if ($msg = RegistrationInputGuard::mobileError((string) $req->mobile)) {
            $guardErrors['mobile'] = [$msg];
        }
        if ($msg = RegistrationInputGuard::ddurnError((string) $req->ddurn_no)) {
            $guardErrors['ddurn_no'] = [$msg];
        }
        if ($msg = RegistrationInputGuard::abcIdError((string) $req->abc_id)) {
            $guardErrors['abc_id'] = [$msg];
        }
        if ($msg = RegistrationInputGuard::familyIdError((string) $req->family_id)) {
            $guardErrors['family_id'] = [$msg];
        }
        if ($guardErrors) {
            return response()->json(['errors' => $guardErrors], 422);
        }

        $req->merge(TextNormalizer::upper($req->all(), [
            'gender',
            'category',
            'admission_category',
            'religion',
            'nationality',
            'domestic_state',
            'caste_cert_state',
            'is_divyang',
            'id_proof_type',
            'course_group',
            'stream',
        ]));

        if (!Cache::get("pre_phone_verified_{$req->mobile}") || !Cache::get("pre_email_verified_{$req->email}")) {
            return response()->json([
                'message' => 'Please verify your mobile and email with OTP before submitting.',
                'errors' => ['otp' => ['Mobile and email must be verified first.']],
            ], 422);
        }

        $regType = strtoupper($req->reg_type);

        // Was missing 'email' here — direct_registrations_email_unique_active
        // is now a live, enforced constraint on (email, session_year,
        // reg_type), but this final pre-insert check only ever tested
        // mobile/aadhar/abc_id. The OTP-send-time check (findDuplicateIdentity())
        // does test email, but that happens minutes earlier and isn't
        // re-verified here — two registrations sharing an email but
        // different mobiles, submitted close together, could both clear the
        // OTP gate and then collide on the real insert below.
        $dup = DB::table('direct_registrations')
            ->where('session_year', $req->session)
            ->where('reg_type', $regType)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($req) {
                $q->where('mobile', $req->mobile)
                    ->orWhere('email', $req->email)
                    ->orWhere('aadhar_no', $req->aadhar_no)
                    ->orWhere('abc_id', $req->abc_id);
            })
            ->first();

        if ($dup) {
            return response()->json([
                'message' => 'A registration already exists for this mobile / Aadhar / ABC ID for this session and course. It must be cancelled by the college office before you can register again for the same course.',
                'registration_id' => $dup->id,
            ], 409);
        }

        // Per explicit instruction: do NOT create the registration (or a
        // user) at all if this email already belongs to a LIVE student
        // account — one with at least one non-cancelled registration.
        // Checked here, before anything is inserted, not inside
        // provisionAccount() further down — by the time that runs the
        // registration row already exists, which is too late to refuse
        // creating it. The one exception is an ORPHANED account (every
        // registration ever linked to it is cancelled) — that's allowed
        // through, and provisionAccount() below reclaims its email for the
        // new registrant, mirroring how it already reclaims an orphaned
        // mobile.
        $emailUser = User::where('email', $req->email)->where('portal', 'student')->first();
        if ($emailUser) {
            $emailUserHasActiveReg = DB::table('direct_registrations')
                ->where('user_id', $emailUser->id)
                ->where('status', '!=', 'cancelled')
                ->whereNull('deleted_at')
                ->exists();
            if ($emailUserHasActiveReg) {
                return response()->json([
                    'message' => 'An account already exists with this email. Please login instead of registering again.',
                ], 409);
            }
        }

        $org = DB::table('organizations')->where('is_active', true)->first();
        $orgId = $org->id ?? null;

        $regNo = $this->generateRegNo($req->session, $req->program_id);
        $password = strtoupper(Str::random(3)) . rand(100, 999) . Str::lower(Str::random(2));
        $uniqueCode = $this->generateUniqueCode($regType, $req->program_id, $req->session);

        try {
            $id = DB::table('direct_registrations')->insertGetId([
                'organization_id' => $orgId,
                'program_id' => $req->program_id,
                'registration_no' => $regNo,
                'unique_code' => $uniqueCode,
                'reg_type' => $regType,
                'session_year' => $req->session,
                'reg_date' => now()->toDateString(),

                'ddurn_no' => $req->ddurn_no,
                'abc_id' => $req->abc_id,
                'family_id' => $req->family_id ?: null,

                'major_subject_1' => $req->major_subject_1 ?: null,
                'major_subject_2' => $req->major_subject_2 ?: null,
                'major_subject_3' => $req->major_subject_3 ?: null,
                'minor_subject_1' => $req->minor_subject_1 ?: null,
                'subject_id' => $req->subject_id ?: null,
                'course_group' => $req->course_group,

                'name' => $req->name,
                'name_hindi' => $req->name_hindi,
                'id_proof_type' => $req->id_proof_type,
                'id_proof_no' => $req->id_proof_no,
                'father_name' => $req->father_name,
                'father_name_hindi' => $req->father_name_hindi,
                'mother_name' => $req->mother_name,
                'mother_name_hindi' => $req->mother_name_hindi,
                'dob' => $req->dob,
                'gender' => $req->gender,
                'domestic_state' => $req->domestic_state,

                'category' => $req->category,
                'admission_category' => $req->admission_category,
                'religion' => $req->religion,
                'nationality' => $req->nationality ?: 'Indian',

                'caste_cert_no' => $req->caste_cert_no,
                'eligibility_class' => $req->eligibility_class,
                'is_divyang' => $req->is_divyang ?: 'No',
                'caste_cert_date' => $req->caste_cert_date ?: null,
                'passing_year' => $req->passing_year,
                'aadhar_no' => $req->aadhar_no,
                'caste_cert_state' => $req->caste_cert_state,
                'eligibility_roll_no' => $req->eligibility_roll_no,

                'email' => $req->email,
                'mobile' => $req->mobile,

                'phone_verified' => true,
                'email_verified' => true,

                'ug_university' => $req->ug_university,
                'ug_institute' => $req->ug_institute,
                'ug_session' => $req->ug_session,
                'ug_roll_no' => $req->ug_roll_no,

                'stream' => $req->stream,
                'entrance_session' => $req->entrance_session,
                'entrance_roll_no' => $req->entrance_roll_no,
                'state_rank' => $req->state_rank,
                'category_rank' => $req->category_rank,
                'cut_off' => $req->cut_off,

                'fee_amount' => $this->resolveRegistrationFee($req->program_id ? (int) $req->program_id : null, $req->session, $req->gender, $req->category, $regType),
                'payment_status' => 'pending',
                'status' => 'pending',

                'temp_password' => Hash::make($password),
                'temp_password_plain' => $password,

                'created_at' => now(),
                'updated_at' => now(),
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
            'reg_no' => $regNo,
            'fee' => $reg->fee_amount,
            'message' => 'Registration saved. Login details have been emailed. Proceed to pay the fee.',
        ], 201);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1b. PRE-REGISTRATION OTP (keyed by mobile/email, before any draft exists)
    //     Used in step 1 so nothing is created until both are verified.
    // ──────────────────────────────────────────────────────────────────────
    private function findDuplicateIdentity(?string $mobile, ?string $email, ?string $aadhar, ?string $regType, ?string $session): ?string
    {
        if ($regType && $session) {
            $dup = DB::table('direct_registrations')
                ->where('session_year', $session)
                ->where('reg_type', strtoupper($regType))
                ->where('status', '!=', 'cancelled')
                ->where(function ($q) use ($mobile, $email, $aadhar) {
                    $q->when($mobile, fn($q2) => $q2->orWhere('mobile', $mobile))
                        ->when($email, fn($q2) => $q2->orWhere('email', $email))
                        ->when($aadhar, fn($q2) => $q2->orWhere('aadhar_no', $aadhar));
                })
                ->exists();

            if ($dup) {
                return 'A registration already exists with this mobile / email / Aadhaar for this session and course. It must be cancelled by the college office before you can register again.';
            }
        }

        // Checked per-matched-account, not as one blanket exists() — an
        // account is only a genuine block if it's still LIVE (has at least
        // one non-cancelled registration on file). Without this, a
        // registrant whose only prior registration was cancelled could
        // never even get past THIS OTP-send gate, making the cancelled-
        // carve-out further down the flow (provisionAccount()'s orphaned-
        // mobile reclaim, and initiate()'s email check) unreachable in
        // practice — the whole point of cancelling is to free the identity
        // back up, not to permanently lock the account.
        $matchedUsers = DB::table('users')
            ->where('portal', 'student')
            ->where(function ($q) use ($mobile, $email) {
                $q->when($mobile, fn($q2) => $q2->orWhere('mobile', $mobile))
                    ->when($email, fn($q2) => $q2->orWhere('email', $email));
            })
            ->get();

        foreach ($matchedUsers as $u) {
            $hasActiveReg = DB::table('direct_registrations')
                ->where('user_id', $u->id)
                ->where('status', '!=', 'cancelled')
                ->whereNull('deleted_at')
                ->exists();
            if ($hasActiveReg) {
                return 'An account already exists with this mobile number or email. Please login instead of registering again.';
            }
        }

        return null;
    }

    public function preSendPhoneOtp(Request $req): JsonResponse
    {
        $req->validate([
            'mobile' => 'required|digits:10',
            'email' => 'nullable|email',
            'aadhar_no' => 'nullable|digits:12',
            'reg_type' => 'nullable|in:UG,PG,BED',
            'session' => 'nullable|string|max:12',
        ]);

        if ($msg = RegistrationInputGuard::mobileError((string) $req->mobile)) {
            return response()->json(['message' => $msg, 'errors' => ['mobile' => [$msg]]], 422);
        }

        if ($dupMsg = $this->findDuplicateIdentity($req->mobile, $req->email, $req->aadhar_no, $req->reg_type, $req->session)) {
            return response()->json(['message' => $dupMsg], 409);
        }

        $otp = rand(100000, 999999);
        Cache::put("pre_phone_otp_{$req->mobile}", $otp, now()->addMinutes(10));

        $sent = app(\App\Services\SmsService::class)->sendOtp($req->mobile, $otp, null);
        if (!$sent) {
            Log::info("PRE PHONE OTP for {$req->mobile}: {$otp}");
            if (!config('app.debug')) {
                return response()->json([
                    'message' => 'Could not send the OTP to your mobile right now. Please try again in a moment.',
                ], 502);
            }
        }

        $resp = ['message' => 'OTP sent to your mobile.'];
        if (config('app.debug'))
            $resp['debug_otp'] = $otp;
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
        $req->validate([
            'email' => 'required|email',
            'mobile' => 'nullable|digits:10',
            'aadhar_no' => 'nullable|digits:12',
            'reg_type' => 'nullable|in:UG,PG,BED',
            'session' => 'nullable|string|max:12',
        ]);

        if ($dupMsg = $this->findDuplicateIdentity($req->mobile, $req->email, $req->aadhar_no, $req->reg_type, $req->session)) {
            return response()->json(['message' => $dupMsg], 409);
        }

        $otp = rand(100000, 999999);
        Cache::put("pre_email_otp_{$req->email}", $otp, now()->addMinutes(10));

        try {
            Mail::raw(
                "Your SDPG College email verification OTP is: {$otp}\n\nValid for 10 minutes. Do not share it with anyone.",
                fn($m) => $m->to($req->email)->subject('SDPG College — Email Verification OTP')
            );
        } catch (\Throwable $e) {
            Log::error('Pre email OTP failed: ' . $e->getMessage());
        }

        $resp = ['message' => 'OTP sent to your email.'];
        if (config('app.debug'))
            $resp['debug_otp'] = $otp;
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
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);
        return response()->json(['data' => $reg]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Office-mode manual verify — one-click override for a registration
    // whose phone_verified / email_verified somehow ended up false
    // ──────────────────────────────────────────────────────────────────────
    public function officeVerifyPhone(int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

        DB::table('direct_registrations')->where('id', $id)
            ->update(['phone_verified' => true, 'updated_at' => now()]);

        return response()->json(['message' => 'Mobile marked as verified.']);
    }

    public function officeVerifyEmail(int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

        DB::table('direct_registrations')->where('id', $id)
            ->update(['email_verified' => true, 'updated_at' => now()]);

        return response()->json(['message' => 'Email marked as verified.']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Office-mode "Fill Form" — create the fresh application on behalf of
    // the student when none exists yet, so a walk-in applicant's paperwork
    // can be filled at the counter.
    // ──────────────────────────────────────────────────────────────────────
    public function officeInitApplication(int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

        if (!$reg->program_id) {
            return response()->json(['message' => 'This registration has no program/class set — cannot start an application.'], 422);
        }

        // Repair a missing unique_code in place, right here, the moment the
        // office actually tries to use this registration. Legacy rows from
        // before this column meant anything on the live DB can have
        // unique_code = null (see ApplicationController::rejectIfCodeInvalid,
        // which now correctly BLOCKS opening an application with no valid
        // code — so a registration stuck at null here would otherwise be
        // permanently unopenable). Only for a still-active registration:
        // a cancelled one is deliberately left alone — cancelling is what
        // frees its identity slot for a fresh registration to reuse, and
        // that new registration will get its own fresh code through
        // initiate() as normal, not through this repair path.
        if (empty($reg->unique_code) && $reg->status !== 'cancelled') {
            $freshCode = self::generateUniqueCode($reg->reg_type, $reg->program_id, $reg->session_year);
            DB::table('direct_registrations')->where('id', $reg->id)->update([
                'unique_code' => $freshCode,
                'updated_at'  => now(),
            ]);
            $reg = $this->findReg($id); // refresh so $reg->unique_code below is current
        }

        $userId = $reg->user_id;

        $existing = DB::table('student_applications')
            ->where('direct_registration_id', $reg->id)
            ->where('application_type', 'regular')
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            // Already there — hand back its id/code so the frontend can
            // just navigate straight in, instead of erroring.
            return response()->json([
                'id' => $existing->id,
                'application_no' => $existing->application_no,
                'code' => $reg->unique_code,
                'message' => 'An application already exists for this registration.',
            ]);
        }

        $seq = DB::table('student_applications')->count() + 1;
        $appNo = 'SA-' . date('Y') . '-' . str_pad($seq, 6, '0', STR_PAD_LEFT);

        // No existingStudent/student_id lookup here, unlike store()'s
        // back_paper/semester_upgrade handling — this endpoint only
        // ever creates 'regular' applications, and a regular applicant has no
        // students row yet by design (it's only ever created at approval,
        // see confirmStudentAndCreateAdmission()). A user_id-keyed lookup
        // would also inherit the same cross-registrant risk noted above,
        // for zero benefit on a type that shouldn't have one anyway.
        $newId = DB::table('student_applications')->insertGetId([
            'organization_id' => $reg->organization_id,
            'user_id' => $userId,
            'student_id' => null,
            'program_id' => $reg->program_id,
            'direct_registration_id' => $reg->id,
            'academic_year' => $reg->session_year,
            'application_type' => 'regular',
            'application_no' => $appNo,
            'status' => 'draft',
            'form_progress' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $newId,
            'application_no' => $appNo,
            // Same contract as store()'s response — frontend carries this
            // as &code= on every URL into the form.
            'code' => $reg->unique_code,
            'message' => 'Application started.',
        ], 201);
    }

    // STUDENT edit — allowed ONLY while the registration fee is unpaid.
    public function updateDraft(Request $req, int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

        if ($reg->payment_status === 'paid') {
            return response()->json(['message' => 'Registration is locked after payment. Please contact the college office to make changes.'], 409);
        }

        if ($denied = $this->applyDraftUpdate($id, $req)) {
            return $denied;
        }
        return response()->json(['message' => 'Registration details updated.']);
    }

    // COLLEGE edit — office staff may modify details at any time, including
    // AFTER payment (used by the "Modify" button on /college/registration/status).
    // Sits behind the authenticated college portal middleware.
    public function adminUpdateDraft(Request $req, int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

        if ($denied = $this->applyDraftUpdate($id, $req)) {
            return $denied;
        }
        return response()->json(['message' => 'Registration details updated by office.']);
    }

    private function applyDraftUpdate(int $id, Request $req): ?JsonResponse
    {
        $fields = [
            'name',
            'name_hindi',
            'father_name',
            'father_name_hindi',
            'mother_name',
            'mother_name_hindi',
            'dob',
            'gender',
            'id_proof_type',
            'id_proof_no',
            'aadhar_no',
            'abc_id',
            'ddurn_no',
            'family_id',
            'domestic_state',
            'category',
            'admission_category',
            'religion',
            'nationality',
            'caste_cert_no',
            'caste_cert_date',
            'caste_cert_state',
            'passing_year',
            'is_divyang',
            'eligibility_class',
            'eligibility_roll_no',
            'course_group',
            'major_subject_1',
            'major_subject_2',
            'major_subject_3',
            'minor_subject_1',
            'subject_id',
            'ug_university',
            'ug_institute',
            'ug_session',
            'ug_roll_no',
            'stream',
            'entrance_session',
            'entrance_roll_no',
            'state_rank',
            'category_rank',
            'cut_off',
        ];
        $selectDrivenFields = [
            'gender',
            'category',
            'admission_category',
            'religion',
            'nationality',
            'domestic_state',
            'caste_cert_state',
            'is_divyang',
            'id_proof_type',
            'course_group',
            'stream',
        ];

        // Same validators initiate() runs at creation, applied here only to
        // whichever of these fields this particular request actually touches.
        $guardErrors = [];
        foreach (['name', 'father_name', 'mother_name'] as $nameField) {
            if ($req->has($nameField) && ($msg = RegistrationInputGuard::nameError((string) $req->input($nameField)))) {
                $guardErrors[$nameField] = [$msg];
            }
        }
        if ($req->has('aadhar_no') && ($msg = RegistrationInputGuard::aadhaarError((string) $req->input('aadhar_no')))) {
            $guardErrors['aadhar_no'] = [$msg];
        }
        if ($req->has('abc_id') && ($msg = RegistrationInputGuard::abcIdError((string) $req->input('abc_id')))) {
            $guardErrors['abc_id'] = [$msg];
        }
        if ($req->has('ddurn_no') && ($msg = RegistrationInputGuard::ddurnError((string) $req->input('ddurn_no')))) {
            $guardErrors['ddurn_no'] = [$msg];
        }
        if ($req->has('family_id') && ($msg = RegistrationInputGuard::familyIdError((string) $req->input('family_id')))) {
            $guardErrors['family_id'] = [$msg];
        }
        if ($guardErrors) {
            return response()->json(['errors' => $guardErrors], 422);
        }

        $update = [];
        foreach ($fields as $f) {
            if (!$req->has($f))
                continue;
            $update[$f] = in_array($f, $selectDrivenFields, true)
                ? ($req->input($f) ?: null)
                : (TextNormalizer::upperValue($req->input($f)) ?: null);
        }
        $update['updated_at'] = now();

        DB::table('direct_registrations')->where('id', $id)->update($update);

        $reg = DB::table('direct_registrations')->where('id', $id)->first();
        if ($reg && $reg->pdf_path) {
            if (Storage::exists($reg->pdf_path)) {
                Storage::delete($reg->pdf_path);
            }
            DB::table('direct_registrations')->where('id', $id)->update(['pdf_path' => null]);
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. PHONE OTP
    // ──────────────────────────────────────────────────────────────────────
    public function sendPhoneOtp(Request $req): JsonResponse
    {
        $id = $this->regId($req);
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

        $otp = rand(100000, 999999);
        Cache::put("reg_phone_otp_{$reg->id}", $otp, now()->addMinutes(10));

        // Send via the Infibrix SMS gateway. When disabled/misconfigured the
        // service returns false — fall back to logging so dev/testing works.
        $sent = app(\App\Services\SmsService::class)->sendOtp($reg->mobile, $otp, $reg);
        if (!$sent) {
            Log::info("PHONE OTP for registration {$reg->id}: {$otp}");

            if (!config('app.debug')) {
                return response()->json([
                    'message' => 'Could not send the OTP to your mobile right now. Please try again in a moment.',
                ], 502);
            }
        }

        $masked = substr($reg->mobile, 0, 2) . 'XXXXXX' . substr($reg->mobile, -2);
        $response = ['message' => "OTP sent to {$masked}."];
        if (config('app.debug'))
            $response['debug_otp'] = $otp;   // only in local/dev

        return response()->json($response);
    }

    public function verifyPhoneOtp(Request $req): JsonResponse
    {
        $id = $this->regId($req);
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

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
        $id = $this->regId($req);
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

        $otp = rand(100000, 999999);
        Cache::put("reg_email_otp_{$reg->id}", $otp, now()->addMinutes(10));

        try {
            Mail::raw(
                "Your SDPG College email verification OTP is: {$otp}\n\n"
                . "Valid for 10 minutes. Do not share it with anyone.",
                fn($m) => $m->to($reg->email)->subject('SDPG College — Email Verification OTP')
            );
        } catch (\Throwable $e) {
            Log::error('Email OTP send failed: ' . $e->getMessage());
            Log::info("EMAIL OTP fallback for registration {$reg->id}: {$otp}");
        }

        $response = ['message' => 'OTP sent to your email.'];
        if (config('app.debug'))
            $response['debug_otp'] = $otp;

        return response()->json($response);
    }

    public function verifyEmailOtp(Request $req): JsonResponse
    {
        $id = $this->regId($req);
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

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
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

        if (!$reg->phone_verified || !$reg->email_verified) {
            return response()->json(['message' => 'Verify mobile and email before paying.'], 422);
        }
        if ($reg->payment_status === 'paid') {
            return response()->json(['message' => 'Registration fee already paid.'], 409);
        }

        $rupees = (int) ($reg->fee_amount ?: (self::FEES[$reg->reg_type] ?? 0));
        $amount = $rupees * 100; // paise

        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');
        if (!$key || !$secret) {
            return response()->json(['message' => 'Payment gateway not configured. Contact the office.'], 503);
        }

        try {
            $resp = Http::withBasicAuth($key, $secret)
                ->asJson()
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $amount,
                    'currency' => 'INR',
                    'receipt' => 'REG_' . $reg->registration_no,
                    'payment_capture' => 1,
                    'notes' => [
                        'registration_id' => (string) $reg->id,
                        'reg_no' => $reg->registration_no,
                        'name' => $reg->name,
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
            'updated_at' => now(),
        ]);

        return response()->json([
            'order_id' => $order['id'],
            'amount' => $amount,        // paise (Razorpay checkout expects paise)
            'amount_rupees' => $rupees,
            'currency' => 'INR',
            'key' => $key,
            'name' => $reg->name,
            'email' => $reg->email,
            'mobile' => $reg->mobile,
            'reg_no' => $reg->registration_no,
            'registration_id' => $reg->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. PAYMENT VERIFY — HMAC check → mark paid, create login, email, receipt
    // ──────────────────────────────────────────────────────────────────────
    public function verifyPayment(Request $req): JsonResponse
    {
        $reg = $this->findReg($this->regId($req));
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

        $req->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $secret = config('services.razorpay.secret');
        $expected = hash_hmac('sha256', $req->razorpay_order_id . '|' . $req->razorpay_payment_id, (string) $secret);

        if (!$secret || !hash_equals($expected, $req->razorpay_signature)) {
            // Treat as a failed payment: still create login + email so the
            // applicant can retry from the portal.
            $this->markFailed($reg);
            return response()->json(['message' => 'Payment could not be verified. Registration saved as incomplete.'], 422);
        }

        $receiptNo = 'RCPT' . date('y') . str_pad($reg->id, 6, '0', STR_PAD_LEFT);

        DB::table('direct_registrations')->where('id', $reg->id)->update([
            'payment_status' => 'paid',
            'status' => 'registered',
            'razorpay_payment_id' => $req->razorpay_payment_id,
            'paid_at' => now(),
            'receipt_no' => $receiptNo,
            'updated_at' => now(),
        ]);

        $reg = $this->findReg($reg->id); // refresh
        $this->provisionAccount($reg, paid: true);

        return response()->json([
            'message' => 'Payment successful. Login credentials have been emailed to you.',
            'registration_id' => $reg->id,
            'reg_no' => $reg->registration_no,
            'receipt_no' => $receiptNo,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. PAYMENT FAILED — client-reported dismissal / failure
    // ──────────────────────────────────────────────────────────────────────
    public function paymentFailed(Request $req): JsonResponse
    {
        $reg = $this->findReg($this->regId($req));
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

        if ($reg->payment_status === 'paid') {
            return response()->json(['message' => 'Payment already completed.'], 409);
        }

        $this->markFailed($reg);

        return response()->json([
            'message' => 'Registration saved but not paid. Login credentials emailed. Pay the fee from the student portal to complete it.',
            'registration_id' => $reg->id,
            'reg_no' => $reg->registration_no,
        ]);
    }

    private function markFailed(object $reg): void
    {
        DB::table('direct_registrations')->where('id', $reg->id)->update([
            'payment_status' => 'failed',
            'status' => 'incomplete',
            'updated_at' => now(),
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
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);
        if ($reg->payment_status !== 'paid') {
            return response()->json(['message' => 'Receipt is available only after successful payment.'], 422);
        }

        $path = $reg->pdf_path;
        if (!$path || !Storage::exists($path)) {
            $path = $this->generateReceiptPdf($reg); // try to (re)generate
        }

        if ($path && Storage::exists($path)) {
            return response()->streamDownload(
                fn() => print (Storage::get($path)),
                "Registration-Receipt-{$reg->registration_no}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        }

        // PDF engine unavailable — return JSON so the client can print its own copy.
        return response()->json([
            'message' => 'PDF unavailable on server; use the on-screen receipt.',
            'data' => $this->receiptData($reg),
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

        if (!$reg)
            return response()->json(['data' => null]);

        return response()->json([
            'data' => [
                'registration_id' => $reg->id,
                'reg_no' => $reg->registration_no,
                'reg_type' => $reg->reg_type,
                'session' => $reg->session_year,
                'fee' => (float) $reg->fee_amount,
                'payment_status' => $reg->payment_status,
                'status' => $reg->status,
            ]
        ]);
    }

    /** Re-initiate payment for the logged-in student's own pending registration. */
    public function payPending(Request $req): JsonResponse
    {
        $user = $req->user();
        $reg = $this->findReg($this->regId($req));

        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);
        if ($reg->user_id && (int) $reg->user_id !== (int) $user->id && $reg->mobile !== $user->mobile) {
            return response()->json(['message' => 'This registration does not belong to you.'], 403);
        }

        $req->merge(['registration_id' => $reg->id]);

        return $this->initiatePayment($req);
    }

    public function studentReceipt(Request $req, int $id)
    {
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);
        $user = $req->user();
        if ($reg->user_id && (int) $reg->user_id !== (int) $user->id && $reg->mobile !== $user->mobile) {
            return response()->json(['message' => 'This registration does not belong to you.'], 403);
        }
        return $this->receipt($id);
    }

    public function studentRegistrationSlip(Request $req, int $id)
    {
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);
        $user = $req->user();
        if ($reg->user_id && (int) $reg->user_id !== (int) $user->id && $reg->mobile !== $user->mobile) {
            return response()->json(['message' => 'This registration does not belong to you.'], 403);
        }
        return $this->registrationSlip($id);
    }

    /** Same ownership rule — the student's own self-edit-while-unpaid draft. */
    public function studentShowDraft(Request $req, int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);
        $user = $req->user();
        if ($reg->user_id && (int) $reg->user_id !== (int) $user->id && $reg->mobile !== $user->mobile) {
            return response()->json(['message' => 'This registration does not belong to you.'], 403);
        }
        return $this->showDraft($req, $id);
    }

    public function studentUpdateDraft(Request $req, int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);
        $user = $req->user();
        if ($reg->user_id && (int) $reg->user_id !== (int) $user->id && $reg->mobile !== $user->mobile) {
            return response()->json(['message' => 'This registration does not belong to you.'], 403);
        }
        return $this->updateDraft($req, $id);
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
            'found' => true,
            'name' => $reg->name,
            'father_name' => $reg->father_name,
            // Hint only — helps the student recall which mobile is their User ID.
            'mobile_hint' => strlen($mobile) === 10 ? substr($mobile, 0, 2) . 'XXXXXX' . substr($mobile, -2) : null,
            'paid' => $reg->payment_status === 'paid',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8b. PUBLIC CATALOG — courses + subjects for the registration form
    //     (the form is public, so these must NOT sit behind auth)
    // ──────────────────────────────────────────────────────────────────────
    public function publicCourses(Request $req): JsonResponse
    {
        // Frontend sends UG | PG | BED; DB enum is UG | PG | BEd.
        $map = ['UG' => 'UG', 'PG' => 'PG', 'BED' => 'BEd', 'BEED' => 'BEd'];
        $level = strtoupper((string) $req->query('level', ''));
        $dbLevel = $map[$level] ?? $level;

        $org = DB::table('organizations')->where('is_active', true)->first();

        $rows = DB::table('programs')
            ->where('is_active', true)
            ->when($org, fn($q) => $q->where('organization_id', $org->id))
            ->when($dbLevel, fn($q) => $q->where('level', $dbLevel))
            ->orderBy('name')
            ->get(['id', 'name', 'short_name', 'code', 'level', 'total_semesters']);

        $data = $rows->map(fn($p) => [
            'id' => $p->id,
            'short_name' => $p->short_name,
            'full_name' => $p->name,           // alias so the dropdown shows the long name
            'name' => $p->name,
            'code' => $p->code,
            'level' => $p->level,
            // crude flag so the form can show the "Group" dropdown for B.Sc only
            'has_group' => stripos($p->short_name . ' ' . $p->name, 'sc') !== false,
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

        $subjects = (clone $base)->when($semester, fn($q) => $q->where('semester_no', $semester))->get();

        // Fallback: if nothing matched the requested semester (e.g. annual courses),
        // return every active subject for the program so the dropdowns still fill.
        if ($subjects->isEmpty()) {
            $subjects = $base->get();
        }

        return response()->json([
            'core' => $subjects->whereIn('type', ['compulsory', 'practical'])->values(),
            'optional' => $subjects->whereIn('type', ['optional', 'elective', 'project'])->values(),
            'all' => $subjects->values(),
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
                'found' => false,
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

        $genderMap = ['male' => 'Male', 'female' => 'Female', 'transgender' => 'Transgender', 'other' => 'Transgender', 'trans' => 'Transgender'];

        $data = [
            'name' => trim("{$student->first_name} " . ($student->middle_name ?? '') . " {$student->last_name}"),
            'gender' => $genderMap[strtolower((string) $student->gender)] ?? null,
            'dob' => $student->date_of_birth,
            'category' => $student->category ? strtoupper($student->category) : null,
            'religion' => $student->religion,
            'nationality' => $student->nationality ?: 'Indian',
            'aadhar_no' => $student->aadhar_no,
            'abc_id' => $student->abc_id,
            'mobile' => $student->mobile,
            'email' => $student->email,
            'domestic_state' => $student->permanent_state,

            // From prior self-registration if available (students table doesn't hold these)
            'name_hindi' => $prior->name_hindi ?? null,
            'father_name' => $prior->father_name ?? null,
            'father_name_hindi' => $prior->father_name_hindi ?? null,
            'mother_name' => $prior->mother_name ?? null,
            'mother_name_hindi' => $prior->mother_name_hindi ?? null,
            'id_proof_type' => $prior->id_proof_type ?? null,
            'id_proof_no' => $prior->id_proof_no ?? null,
            'caste_cert_no' => $prior->caste_cert_no ?? null,
            'caste_cert_date' => $prior->caste_cert_date ?? null,
            'caste_cert_state' => $prior->caste_cert_state ?? null,
            'ddurn_no' => $prior->ddurn_no ?? null,
            'family_id' => $prior->family_id ?? null,
        ];

        return response()->json([
            'found' => true,
            'source' => $prior ? 'students+prior_registration' : 'students',
            'data' => array_filter($data, fn($v) => $v !== null && $v !== ''),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8d. COUNSELLING LOOKUP — for programs whose admission_conditions row is
    //     "Through Counselling". Unlike fetchOldRecord()
    //     GET /student/register/counselling-lookup?program_id=&session_year=&entrance_roll_no=
    // ──────────────────────────────────────────────────────────────────────
    public function counsellingLookup(Request $req): JsonResponse
    {
        $req->validate([
            'program_id' => 'required|exists:programs,id',
            'session_year' => 'required|string',
            'entrance_roll_no' => 'required|string',
        ]);

        $row = DB::table('counselling_reports')
            ->where('program_id', $req->program_id)
            ->where('session_year', $req->session_year)
            ->where('entrance_roll_no', trim($req->entrance_roll_no))
            ->first();

        if (!$row) {
            return response()->json([
                'found' => false,
                'message' => 'No counselling record found for this roll number under the selected class and session.',
            ]);
        }

        return response()->json([
            'found' => true,
            'source' => 'counselling_reports',
            'data' => [
                'name' => $row->name,
                'father_name' => $row->father_name,
                'mother_name' => $row->mother_name,
                'gender' => $row->gender,
                'category' => $row->social_category,
            ],
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
                'dr.id',
                'dr.registration_no',
                'dr.reg_type',
                'dr.session_year',
                'dr.name',
                'dr.father_name',
                'dr.mobile',
                'dr.email',
                'dr.category',
                'dr.gender',
                'dr.phone_verified',
                'dr.email_verified',
                'dr.fee_amount',
                'dr.payment_status',
                'dr.status',
                'dr.paid_at',
                'dr.razorpay_payment_id',
                'dr.created_at',
                'p.short_name as program',
                'p.full_name as program_name'
            )
            ->when($req->payment_status, fn($q) => $q->where('dr.payment_status', $req->payment_status))
            ->when($req->reg_type, fn($q) => $q->where('dr.reg_type', strtoupper($req->reg_type)))
            ->when($req->session, fn($q) => $q->where('dr.session_year', $req->session))
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
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
    //     again for the same session + course
    // ──────────────────────────────────────────────────────────────────────
    public function cancelRegistration(Request $req, int $id): JsonResponse
    {
        $reg = $this->findReg($id);
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);

        if ($reg->status === 'cancelled') {
            return response()->json(['message' => 'Registration is already cancelled.'], 409);
        }

        $req->validate(['reason' => 'nullable|string|max:500']);

        DB::table('direct_registrations')->where('id', $id)->update([
            'status' => 'cancelled',
            'cancelled_by' => $req->user()?->id,
            'cancelled_at' => now(),
            'cancel_reason' => $req->reason,
            'updated_at' => now(),
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

            if ($user && (int) ($reg->user_id ?? 0) !== $user->id) {
                $hasActiveRegistration = DB::table('direct_registrations')
                    ->where('mobile', $reg->mobile)
                    ->where('id', '!=', $reg->id)
                    ->where('status', '!=', 'cancelled')
                    ->whereNull('deleted_at')
                    ->exists();

                if (!$hasActiveRegistration) {
                    // Orphaned: free the mobile from the old account
                    Log::warning("provisionAccount: reclaiming mobile {$reg->mobile} from orphaned user {$user->id} (all of its direct_registrations are cancelled) for new registration {$reg->id}");
                    $user->update(['mobile' => null]);
                    $user = null;
                }
            }

            if (!$user) {
                // users_email_unique_idx is global (not portal/mobile-scoped)
                // and now a live constraint. initiate() already refuses to
                // create a registration at all when this email belongs to a
                // LIVE account (one with an active registration) — so if we
                // reach here and the email is still taken, that other
                // account is guaranteed orphaned (every registration linked
                // to it was cancelled). Reclaim its email the same way the
                // mobile-orphan case above reclaims a mobile, rather than
                // dropping the new registrant's own email — there's no
                // longer a live account to protect by doing that.
                if ($reg->email) {
                    $emailOwner = User::where('email', $reg->email)->first();
                    if ($emailOwner) {
                        Log::warning("provisionAccount: reclaiming email {$reg->email} from orphaned user {$emailOwner->id} for new registration {$reg->id}");
                        $emailOwner->update(['email' => null]);
                    }
                }
                $user = User::create([
                    'organization_id' => $reg->organization_id,
                    'name' => $reg->name,
                    'mobile' => $reg->mobile,
                    'email' => $reg->email,
                    'password' => $plain,
                    'portal' => 'student',
                    'is_active' => true,
                ]);
                try {
                    $user->assignRole('student');
                } catch (\Throwable $e) {
                    Log::warning('assignRole(student) failed: ' . $e->getMessage());
                }
            }

            $update = [
                'user_id' => $user->id,
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

    private function generateReceiptPdf(object $reg): ?string
    {
        try {
            $program = $reg->program_id ? DB::table('programs')->find($reg->program_id) : null;
            $org = $reg->organization_id ? DB::table('organizations')->find($reg->organization_id) : null;

            $pdf = Pdf::loadView('pdf.registration-receipt', [
                'reg' => $reg,
                'program' => $program,
                'org' => $org,
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
        $course = $program->full_name ?? $program->short_name ?? $reg->reg_type;
        $fee = (int) ($reg->fee_amount ?: (self::FEES[$reg->reg_type] ?? 0));
        $portal = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://erp.sdpgcollege.ac.in')), '/');

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
            Mail::raw($body, fn($m) => $m->to($reg->email)->subject($subject));
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
        if (!$reg)
            return response()->json(['message' => 'Registration not found.'], 404);
        if ($reg->payment_status !== 'paid') {
            return response()->json(['message' => 'Registration slip is available only after payment.'], 422);
        }

        $program = $reg->program_id ? DB::table('programs')->find($reg->program_id) : null;
        $org = $reg->organization_id ? DB::table('organizations')->find($reg->organization_id) : null;

        $subjIds = array_filter([
            $reg->major_subject_1 ?? null,
            $reg->major_subject_2 ?? null,
            $reg->major_subject_3 ?? null,
            $reg->minor_subject_1 ?? null,
            $reg->subject_id ?? null,
        ]);
        $names = $subjIds ? DB::table('subjects')->whereIn('id', $subjIds)->pluck('name', 'id')->all() : [];

        $minor = null;
        if (!empty($reg->minor_subject_1)) {
            $minor = $names[$reg->minor_subject_1] ?? null;
            if (!$minor) {
                $vp = DB::table('vocational_papers')->find($reg->minor_subject_1);
                if ($vp) {
                    $minor = trim($vp->paper_name . ($vp->paper_code ? " ({$vp->paper_code})" : ''));
                }
            }
        }

        $subjects = array_filter([
            'Major Subject 1' => $names[$reg->major_subject_1] ?? null,
            'Major Subject 2' => $names[$reg->major_subject_2] ?? null,
            'Major Subject 3' => $names[$reg->major_subject_3] ?? null,
            'Minor Subject 1' => $minor,
            'Subject' => $names[$reg->subject_id] ?? null,
        ]);

        try {
            $pdf = Pdf::loadView('pdf.registration-slip', compact('reg', 'program', 'org', 'subjects'))->setPaper('a4');
            return response()->streamDownload(
                fn() => print ($pdf->output()),
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
            'reg_no' => $reg->registration_no,
            'receipt_no' => $reg->receipt_no,
            'name' => $reg->name,
            'father_name' => $reg->father_name,
            'mobile' => $reg->mobile,
            'email' => $reg->email,
            'course' => $program->full_name ?? $program->short_name ?? $reg->reg_type,
            'session' => $reg->session_year,
            'fee' => (float) $reg->fee_amount,
            'payment_id' => $reg->razorpay_payment_id,
            'paid_at' => $reg->paid_at,
            'status' => $reg->status,
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
        $reg = DB::table('direct_registrations')->whereNull('deleted_at')->find($id);
        if ($reg) {
            $reg->phone_verified = (bool) $reg->phone_verified;
            $reg->email_verified = (bool) $reg->email_verified;
        }
        return $reg;
    }

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
                $genderKey = strtolower($gender ?: 'male');
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

    public function registrationMeta(Request $req): JsonResponse
    {
        $regType = strtoupper((string) $req->reg_type);
        $programId = $req->program_id ? (int) $req->program_id : null;

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
            'session_year' => $session,
            'fee' => $fee,
            'admission_condition' => $condition,
        ]);
    }

    private function generateRegNo(string $session, $programId): string
    {
        [$ys, $ye] = $this->sessionYears($session);
        $progCode = str_pad((string) ($programId ?: 0), 2, '0', STR_PAD_LEFT);
        $seq = DB::table('direct_registrations')
            ->where('session_year', $session)
            ->when($programId, fn($q) => $q->where('program_id', $programId))
            ->count() + 1;

        return $ys . $ye . $progCode . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public static function generateUniqueCode(string $regType, $programId, string $session): string
    {
        $timestamp = now()->format('YmdHis'); // 14 digits, sortable and unique per second

        $courseCode = 'GEN';
        if ($programId) {
            $program = DB::table('programs')->where('id', $programId)->first();
            if ($program && !empty($program->code)) {
                $courseCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $program->code));
            }
        }

        [$ys, $ye] = self::sessionYears($session); // e.g. "25", "26"
        $sessionCode = $ys . $ye;                       // "2526"

        $mode = 'REGULAR'; // only mode supported today — extend here if more modes are added later
        $random = (string) random_int(100000, 999999); // 6-digit random tail

        $segments = [$timestamp, strtoupper($regType), $courseCode, $sessionCode, $mode, $random];
        $code = implode('-', $segments);

        while (DB::table('direct_registrations')->where('unique_code', $code)->exists()) {
            $random = (string) random_int(100000, 999999);
            $segments[5] = $random;
            $code = implode('-', $segments);
        }

        return $code;
    }

    private static function sessionYears(string $session): array
    {
        $parts = explode('-', $session);
        return [
            substr($parts[0] ?? date('Y'), 2, 2),
            substr($parts[1] ?? (string) (date('Y') + 1), 2, 2),
        ];
    }

}
