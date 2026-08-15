<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthorizationController;
use App\Http\Controllers\Api\FeeHeadController;
use App\Http\Controllers\Api\FinancialController;
use App\Http\Controllers\Api\MasterSettingsController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\SmsTemplateController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\FeeReceiptController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\StudentRegistrationController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\FeeStructureController;
use App\Http\Controllers\Api\AdmissionController;
use App\Http\Controllers\Api\ExaminationController;
use App\Http\Controllers\Api\AmendmentController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\FeesController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\PaymentLedgerController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

// ─── Public Routes ─────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('student/login', [AuthController::class, 'studentLogin']);
    Route::get('student/lookup', [StudentRegistrationController::class, 'loginLookup']);
    Route::post('student/register', [StudentRegistrationController::class, 'register']);
    Route::post('student/check-mobile', [StudentRegistrationController::class, 'checkMobile']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('student/forgot-password', [AuthController::class, 'studentForgotPassword']);
    Route::post('student/reset-password', [AuthController::class, 'studentResetPassword']);
});

Route::prefix('student/register')->group(function () {
    // Public catalog for the registration form (form is public — must not need auth)
    Route::get('courses', [StudentRegistrationController::class, 'publicCourses']);
    Route::get('programs/{program}/subjects', [StudentRegistrationController::class, 'publicSubjects']);
    Route::get('meta', [StudentRegistrationController::class, 'registrationMeta']);
    // Groupwise (A/B/C) subjects for the public registration form dropdowns
    Route::get('subject-groups', [MasterSettingsController::class, 'publicSubjectGroups']);
    // Vocational / co-curricular papers — populates the Minor Subject dropdown
    Route::get('vocational-papers', [MasterSettingsController::class, 'publicVocationalPapers']);

    // Admission-condition-driven record lookups (see MasterSettingsController's
    // admission_conditions feature). "Open Admission" checks for a returning
    // DDU student in `students`; "Through Counselling" checks `counselling_reports`.
    // fetchOldRecord() already existed but was never routed — the PG/BEd "Fetch
    // Record" button has been calling this exact path and silently 404ing.
    Route::get('fetch-record', [StudentRegistrationController::class, 'fetchOldRecord']);
    Route::get('counselling-lookup', [StudentRegistrationController::class, 'counsellingLookup']);

    // Step-1 OTP — verify mobile + email BEFORE anything is created (keyed by contact).
    Route::post('otp/phone/send', [StudentRegistrationController::class, 'preSendPhoneOtp']);
    Route::post('otp/phone/verify', [StudentRegistrationController::class, 'preVerifyPhoneOtp']);
    Route::post('otp/email/send', [StudentRegistrationController::class, 'preSendEmailOtp']);
    Route::post('otp/email/verify', [StudentRegistrationController::class, 'preVerifyEmailOtp']);

    // Create the draft (only succeeds once both OTPs are verified).
    Route::post('init', [StudentRegistrationController::class, 'initiate']);

    // Edit an unpaid draft (college "Modify" + student editing while payment pending).
    Route::get('draft/{id}', [StudentRegistrationController::class, 'showDraft']);
    Route::put('draft/{id}', [StudentRegistrationController::class, 'updateDraft']);

    Route::post('payment/initiate', [StudentRegistrationController::class, 'initiatePayment']);
    Route::post('payment/verify', [StudentRegistrationController::class, 'verifyPayment']);
    Route::post('payment/failed', [StudentRegistrationController::class, 'paymentFailed']);

    Route::get('receipt/{id}', [StudentRegistrationController::class, 'receipt']);
    // Registration slip — full details + payment status (available once paid).
    Route::get('slip/{id}', [StudentRegistrationController::class, 'registrationSlip']);
});


Route::get('bank/search', function (Request $request) {
    $q = trim($request->query('q', ''));

    if (strlen($q) < 2) {
        return response()->json([]);
    }

    $like = '%' . strtoupper($q) . '%';
    $upper = strtoupper($q);

    $branches = DB::table('bank_branches')
        ->where(function ($query) use ($like) {
            $query->whereRaw('UPPER(ifsc_code)   LIKE ?', [$like])
                ->orWhereRaw('UPPER(branch_name) LIKE ?', [$like])
                ->orWhereRaw('UPPER(city)        LIKE ?', [$like])
                ->orWhereRaw('UPPER(district)    LIKE ?', [$like])
                ->orWhereRaw('UPPER(bank_name)   LIKE ?', [$like]);
        })
        ->select('id', 'ifsc_code', 'bank_name', 'branch_name', 'city', 'district', 'state', 'micr_code', 'address')
        // Exact IFSC first; branch_name tiebreak so shared-IFSC rows don't
        // come back in arbitrary order (2000 rows all matched rank 0 before).
        ->orderByRaw('CASE WHEN UPPER(ifsc_code) = ? THEN 0 ELSE 1 END', [$upper])
        ->orderBy('bank_name')
        ->orderBy('branch_name')
        ->limit(20)
        ->get();

    return response()->json($branches);
});


Route::get('bank/ifsc/{code}', function (Request $request, string $code) {
    $ifsc = strtoupper(trim($code));
    $q = trim($request->query('q', ''));

    $base = DB::table('bank_branches')->where('ifsc_code', $ifsc);

    $total = (clone $base)->count();
    if ($total === 0) {
        return response()->json(['message' => 'IFSC not found.'], 404);
    }

    if ($q !== '') {
        $like = '%' . strtoupper($q) . '%';
        $base->where(function ($query) use ($like) {
            $query->whereRaw('UPPER(branch_name) LIKE ?', [$like])
                ->orWhereRaw('UPPER(city)        LIKE ?', [$like])
                ->orWhereRaw('UPPER(district)    LIKE ?', [$like]);
        });
    }

    $branches = $base
        ->select('id', 'ifsc_code', 'bank_name', 'branch_name', 'city', 'district', 'state', 'micr_code', 'address', 'phone')
        ->orderBy('branch_name')
        ->limit(50)
        ->get();

    return response()->json([
        'ifsc_code' => $ifsc,
        'bank_name' => $branches->first()->bank_name ?? null,
        'multiple' => $total > 1,
        'total' => $total,
        'branches' => $branches,
    ]);
});

// PIN code -> district/state autofill, and University name autocomplete.
// Public: also backs the public student registration/application forms.
Route::get('pincode/search', [LookupController::class, 'pincodeSearch']);
Route::get('pincode/states', [LookupController::class, 'states']);
Route::get('pincode/districts', [LookupController::class, 'districts']);
Route::get('pincode/{code}', [LookupController::class, 'pincode']);

Route::get('university/search', [LookupController::class, 'universitySearch']);
Route::get('university/states', [LookupController::class, 'universityStates']);

// ─── Authenticated Routes ───────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    // ── COLLEGE PORTAL ────────────────────────────────────────────────────
    // portal enum is college|student only — 'super_admin' is a role
    // (checked via Spatie permissions/roles), not a portal value.
    Route::middleware('portal:college')->group(function () {

        // Dashboard
        Route::get('dashboard', [DashboardController::class, 'index']);

        // program
        Route::apiResource('programs', ProgramController::class);
        Route::get('programs/{program}/subjects', [SubjectController::class, 'byProgram']);

        //subjects
        Route::apiResource('subjects', SubjectController::class);

        // Organization
        Route::apiResource('organizations', OrganizationController::class);
        Route::get('organization', [OrganizationController::class, 'show']);
        Route::put('organization', [OrganizationController::class, 'update']);
        Route::post('organization/logo', [OrganizationController::class, 'uploadLogo']);

        // Fee Settings
        Route::apiResource('fee-heads', FeeHeadController::class);
        Route::apiResource('fee-structures', FeeStructureController::class);
        Route::post('fee-structures/copy-from-year', [FeeStructureController::class, 'copyFromYear']);

        // SMS Templates
        Route::apiResource('sms-templates', SmsTemplateController::class);
        Route::post('sms-templates/{smsTemplate}/preview', [SmsTemplateController::class, 'preview']);

        // Students
        Route::apiResource('students', StudentController::class)->except('destroy');
        Route::post('students/{student}/photo', [StudentController::class, 'uploadPhoto']);
        Route::post('students/{student}/signature', [StudentController::class, 'uploadSignature']);
        Route::post('students/{student}/block', [StudentController::class, 'blockUnblock']);
        Route::get('students/{student}/ledger', [StudentController::class, 'ledger']);
        Route::get('students/statistics', [StudentController::class, 'statistics']);

        // Applications (Office view)
        Route::prefix('applications')->group(function () {

            // GET  /applications
            Route::get('/', [ApplicationController::class, 'index']);

            // POST /applications   (office creates back-paper / upgrade app on behalf of student)
            Route::post('/', [ApplicationController::class, 'storeOffice']);

            // GET  /applications/office-lookup?q=
            Route::get('/office-lookup', [ApplicationController::class, 'officeLookup']);

            // GET  /applications/hold
            // POST /applications/hold
            Route::get('/hold', [ApplicationController::class, 'holdIndex']);
            Route::post('/hold', [ApplicationController::class, 'holdStore']);

            // GET  /applications/back-paper/papers?admission_id=&semester_no=
            Route::get('/back-paper/papers', [ApplicationController::class, 'backPaperPapers']);

            // GET  /applications/registration-form-status  (alias — old frontend URL)
            Route::get('/registration-form-status', [ApplicationController::class, 'registrationFormStatus']);

            // Office (read-only) equivalents of the student-portal subjects /
            // subject-papers lookups used by Part 6 "Subject & Paper Selection"
            // — same controller methods, reachable from a portal:college token
            // instead of portal:student, since that form is shared between both
            // scopes and the student-only routes 403 for office staff.
            Route::get('/programs/{programId}/subjects', [ProgramController::class, 'subjects']);
            Route::get('/programs/{programId}/subject-papers', [ProgramController::class, 'subjectPapers']);

            // ── Wildcard — MUST be last inside this prefix group ─────────────────────

            // GET  /applications/{id}
            Route::get('/{id}', [ApplicationController::class, 'show'])->whereNumber('id');

            // PUT  /applications/{id}/part/{part}   (office edits any part — no ownership check)
            Route::put('/{id}/part/{part}', [ApplicationController::class, 'updatePartOffice']);

            // GET  /applications/{id}/print   (full application PDF — completed & fee-paid only)
            Route::get('/{id}/print', [ApplicationController::class, 'printForm']);

            // Education fee (fresh / semester_upgrade / lateral) — office
            Route::post('/{id}/pay/initiate', [ApplicationController::class, 'applicationPayInitiate']);
            Route::post('/{id}/pay/verify', [ApplicationController::class, 'applicationPayVerify']);
            Route::post('/{id}/pay/failed', [ApplicationController::class, 'applicationPayFailed']);

            // Back paper — office save / pay / print (fee comes from fee_structures master)
            Route::put('/{id}/back-paper', [ApplicationController::class, 'saveBackPaperSelection']);
            Route::post('/{id}/back-paper/pay/initiate', [ApplicationController::class, 'backPaperPayInitiate']);
            Route::post('/{id}/back-paper/pay/verify', [ApplicationController::class, 'backPaperPayVerify']);
            Route::post('/{id}/back-paper/pay/failed', [ApplicationController::class, 'backPaperPayFailed']);
            Route::get('/{id}/back-paper/print', [ApplicationController::class, 'printBackPaperForm']);

            // PATCH /applications/{id}/status   (approve / reject / reopen)
            Route::patch('/{id}/status', [ApplicationController::class, 'updateStatus']);

            // PATCH /applications/{id}/release-hold
            Route::patch('/{id}/release-hold', [ApplicationController::class, 'holdRelease']);
        });

        // Admissions
        Route::prefix('admissions')->group(function () {
            Route::apiResource('/', AdmissionController::class)->except(['store', 'destroy']);
            Route::post('{admission}/verify', [AdmissionController::class, 'verify']);
            Route::post('{admission}/cancel', [AdmissionController::class, 'cancel']);
            Route::get('statistics', [AdmissionController::class, 'statistics']);
            Route::get('upgrade-list', [AdmissionController::class, 'upgradeList']);
            Route::post('{admission}/upgrade', [AdmissionController::class, 'upgrade']);
            Route::get('biometrics', [AdmissionController::class, 'biometrics']);
            Route::patch('biometrics/{student}', [AdmissionController::class, 'updateBiometric']);
            Route::get('education-fee', [AdmissionController::class, 'educationFee']);
            Route::get('ledger', [AdmissionController::class, 'ledger']);
            Route::get('statistics', [AdmissionController::class, 'statistics']);
            Route::get('subject-statistics', [AdmissionController::class, 'subjectStatistics']);
        });


        // Fee Receipts
        Route::get('fee-receipts', [FeeReceiptController::class, 'index']);
        Route::get('fee-receipts/{feeReceipt}', [FeeReceiptController::class, 'show']);
        Route::post('fee-receipts/generate', [FeeReceiptController::class, 'generate']);
        Route::get('fee-receipts/{feeReceipt}/download', [FeeReceiptController::class, 'download']);
        Route::post('fee-receipts/{feeReceipt}/verify', [FeeReceiptController::class, 'verify']);
        Route::post('fee-receipts/{feeReceipt}/cancel', [FeeReceiptController::class, 'cancel']);
        Route::get('fee-receipts/summary', [FeeReceiptController::class, 'financialSummary']);


        // Registration
        Route::prefix('registration')->group(function () {
            // UG / PG / B.Ed registration list + actions (level passed as query param)
            Route::get('/', [RegistrationController::class, 'index']);
            Route::post('/', [RegistrationController::class, 'store']);
            Route::patch('{id}/status', [RegistrationController::class, 'updateStatus']);
            Route::post('bulk-approve', [RegistrationController::class, 'bulkApprove']);

            // Registration Status summary
            Route::get('status', [RegistrationController::class, 'registrationStatus']);

            // Office "Modify" — view + edit a registration's details, allowed
            // even AFTER payment (students are locked out once paid).
            Route::get('{id}/details', [StudentRegistrationController::class, 'showDraft']);
            Route::put('{id}/details', [StudentRegistrationController::class, 'adminUpdateDraft']);

            // Cancel — the only way the same mobile/aadhar/abc_id can register
            // again for the same session + course.
            Route::post('{id}/cancel', [StudentRegistrationController::class, 'cancelRegistration']);

            // Student Status lookup
            Route::get('student-status', [RegistrationController::class, 'studentStatus']);

            // Stats
            Route::get('stats', [RegistrationController::class, 'stats']);
            Route::get('self-registered', [StudentRegistrationController::class, 'adminIndex']);

        });

        // ── Examination ───────────────────────────────────────────────────────
        Route::prefix('examination')->group(function () {

            // Accept Exam Form
            Route::get('accept-form', [ExaminationController::class, 'acceptFormIndex']);
            Route::patch('accept-form/{id}', [ExaminationController::class, 'acceptFormUpdate']);
            Route::post('accept-form/bulk-accept', [ExaminationController::class, 'acceptFormBulk']);

            // Exam Form ID Entry
            Route::get('form-id', [ExaminationController::class, 'formIdIndex']);
            Route::patch('form-id/{id}', [ExaminationController::class, 'formIdUpdate']);

            // Exam Schedule
            Route::get('schedule', [ExaminationController::class, 'scheduleIndex']);
            Route::post('schedule', [ExaminationController::class, 'scheduleStore']);
            Route::patch('schedule/{id}', [ExaminationController::class, 'scheduleUpdate']);
            Route::delete('schedule/{id}', [ExaminationController::class, 'scheduleDestroy']);
            Route::get('schedule/search', [ExaminationController::class, 'scheduleSearch']);

            // Room Master
            Route::get('rooms', [ExaminationController::class, 'roomMasterIndex']);
            Route::post('rooms', [ExaminationController::class, 'roomMasterStore']);
            Route::put('rooms/{id}', [ExaminationController::class, 'roomMasterUpdate']);
            Route::delete('rooms/{id}', [ExaminationController::class, 'roomMasterDestroy']);

            // Inning Setting
            Route::get('innings', [ExaminationController::class, 'inningIndex']);
            Route::post('innings', [ExaminationController::class, 'inningStore']);
            Route::put('innings/{id}', [ExaminationController::class, 'inningUpdate']);
            Route::delete('innings/{id}', [ExaminationController::class, 'inningDestroy']);

            // Seating Plan
            Route::post('seating-plan/create', [ExaminationController::class, 'seatingPlanCreate']);
            Route::get('seating-plan/search-seat', [ExaminationController::class, 'searchSeat']);
            Route::post('seating-plan/self-p7', [ExaminationController::class, 'selfCreateP7']);

            // Exam Conduct
            Route::get('conduct/p1', [ExaminationController::class, 'conductP1Index']);
            Route::post('conduct/p1', [ExaminationController::class, 'conductP1Store']);
            Route::get('conduct/p3', [ExaminationController::class, 'conductP3Index']);
            Route::post('conduct/p3', [ExaminationController::class, 'conductP3Store']);
            Route::get('conduct/p4', [ExaminationController::class, 'conductP4Index']);
            Route::post('conduct/p4', [ExaminationController::class, 'conductP4Store']);
            Route::get('conduct/p9', [ExaminationController::class, 'conductP9Index']);
            Route::post('conduct/p9', [ExaminationController::class, 'conductP9Store']);

            // Nominal Roll
            Route::get('nominal-roll', [ExaminationController::class, 'nominalRollIndex']);
            Route::patch('nominal-roll/{id}', [ExaminationController::class, 'nominalRollUpdate']);

            // Result Update
            Route::get('result', [ExaminationController::class, 'resultIndex']);
            Route::patch('result/{id}', [ExaminationController::class, 'resultUpdate']);
            Route::post('result/bulk', [ExaminationController::class, 'resultBulkUpdate']);

            // Marksheet Distribution
            Route::get('marksheet', [ExaminationController::class, 'marksheetIndex']);
            Route::patch('marksheet/{id}', [ExaminationController::class, 'marksheetUpdateAvailability']);

            // Statistics
            Route::get('stats/examinee', [ExaminationController::class, 'examineeStats']);
            Route::get('stats/subject', [ExaminationController::class, 'subjectStats']);

            // Other Exam Centre
            Route::get('centres', [ExaminationController::class, 'examCentreIndex']);
            Route::post('centres', [ExaminationController::class, 'examCentreStore']);
            Route::put('centres/{id}', [ExaminationController::class, 'examCentreUpdate']);
            Route::delete('centres/{id}', [ExaminationController::class, 'examCentreDestroy']);
            Route::get('centres/students', [ExaminationController::class, 'examCentreStudents']);

            // Helpers
            Route::get('lookup-student', [ExaminationController::class, 'lookupStudent']);
        });

        // ── Amendment ───────────────────────────────────────────────────────
        Route::prefix('amendments')->group(function () {

            // Search
            Route::get('/search', [AmendmentController::class, 'search']);

            // Modify student data
            Route::get('/modify-data', [AmendmentController::class, 'modifyGet']);
            Route::patch('/modify-data', [AmendmentController::class, 'modifyUpdate']);

            // Subject change
            Route::get('/subject-change', [AmendmentController::class, 'subjectChangeGet']);
            Route::post('/subject-change', [AmendmentController::class, 'subjectChangeStore']);

            // Update mobile
            Route::get('/update-mobile', [AmendmentController::class, 'updateMobileGet']);
            Route::post('/update-mobile', [AmendmentController::class, 'updateMobileStore']);
            Route::post('/update-mobile/send-otp', [AmendmentController::class, 'sendOtp']);

            // Update TC & Migration
            Route::get('/update-tc', [AmendmentController::class, 'updateTcGet']);
            Route::post('/update-tc', [AmendmentController::class, 'updateTcStore']);

            // Update paper for student
            Route::get('/update-paper', [AmendmentController::class, 'updatePaperIndex']);
            Route::post('/update-paper', [AmendmentController::class, 'updatePaperStore']);

            // Download documents
            Route::get('/download-documents', [AmendmentController::class, 'downloadDocuments']);

            // Import / export data
            Route::post('/import-data', [AmendmentController::class, 'importData']);

            // Fee value change
            Route::get('/fee-value-change', [AmendmentController::class, 'feeValueChangeGet']);
            Route::post('/fee-value-change', [AmendmentController::class, 'feeValueChangeStore']);

            // Fee reset
            Route::get('/fee-reset', [AmendmentController::class, 'feeResetGet']);
            Route::post('/fee-reset', [AmendmentController::class, 'feeResetStore']);

            // Block / Unblock
            Route::get('/block-unblock', [AmendmentController::class, 'blockUnblockGet']);
            Route::post('/block-unblock', [AmendmentController::class, 'blockUnblockStore']);

            // Restriction
            Route::get('/restriction', [AmendmentController::class, 'restrictionIndex']);
            Route::post('/restriction', [AmendmentController::class, 'restrictionStore']);
            Route::delete('/restriction/{studentId}', [AmendmentController::class, 'restrictionRemove']);

            // Admission cancel
            Route::get('/admission-cancel', [AmendmentController::class, 'admissionCancelGet']);
            Route::post('/admission-cancel', [AmendmentController::class, 'admissionCancelStore']);

            // Hold or cancel — by college
            Route::get('/hold-cancel', [AmendmentController::class, 'holdCancelIndex']);
            Route::post('/hold-cancel', [AmendmentController::class, 'holdCancelStore']);

            // Amendment log / approval
            Route::get('/logs', [AmendmentController::class, 'logIndex']);
            Route::patch('/logs/{id}/approve', [AmendmentController::class, 'logApprove']);
        });

        Route::prefix('authorizations')->group(function () {
            // 1. Admission Verification — Odd semesters (1,3,5,7)
            Route::get('/admission-verification', [AuthorizationController::class, 'admissionVerificationIndex']);
            Route::get('/admission-verification/{admissionId}', [AuthorizationController::class, 'admissionVerificationShow']);
            Route::post('/admission-verification/{admissionId}/action', [AuthorizationController::class, 'admissionVerificationAction']);

            // 2. Semester Registration Approval — Even semesters (2,4,6,8)
            Route::get('/semester-approval', [AuthorizationController::class, 'semesterApprovalIndex']);
            Route::post('/semester-approval/{admissionId}/action', [AuthorizationController::class, 'semesterApprovalAction']);

            // 3. Fee Receipt Verification
            Route::get('/fee-receipt', [AuthorizationController::class, 'feeReceiptIndex']);
            Route::post('/fee-receipt/{id}/verify', [AuthorizationController::class, 'feeReceiptVerify']);

            // 4. Misc. Activity Verification
            Route::get('/misc-activity', [AuthorizationController::class, 'miscActivityIndex']);
            Route::post('/misc-activity/{id}/action', [AuthorizationController::class, 'miscActivityAction']);

            // 5. Block / Unblock User
            Route::get('/block-unblock', [AuthorizationController::class, 'blockUnblockSearch']);
            Route::post('/block-unblock', [AuthorizationController::class, 'blockUnblockAction']);
        });

        Route::prefix('financial')->group(function () {

            // 1. Create Fee Transfer Voucher
            Route::get('/fee-transfer-voucher', [FinancialController::class, 'feeTransferVoucherIndex']);
            Route::post('/fee-transfer-voucher', [FinancialController::class, 'feeTransferVoucherStore']);

            // 2. Online Fee Accept
            Route::get('/online-fee-accept', [FinancialController::class, 'onlineFeeAcceptSearch']);
            Route::post('/online-fee-accept', [FinancialController::class, 'onlineFeeAcceptStore']);

            // 3. Update Transaction
            Route::get('/update-transaction', [FinancialController::class, 'updateTransactionSearch']);
            Route::post('/update-transaction', [FinancialController::class, 'updateTransactionStore']);
        });

        Route::prefix('fees')->group(function () {

            // All Fee Receipts — paginated list with filters
            Route::get('receipts', [FeesController::class, 'receiptsIndex']);

            // Verify Fee Receipts — list pending, verify/reject
            Route::get('verify', [FeesController::class, 'verifyIndex']);
            Route::post('verify/{id}/{act}', [FeesController::class, 'verifyAction']);  // act = verify|reject

            // Student Ledger — per-student transaction history
            Route::get('ledger', [FeesController::class, 'ledgerIndex']);

            // Financial Summary — aggregate stats
            Route::get('summary', [FeesController::class, 'summaryIndex']);
        });

        // Certificates
        Route::apiResource('certificates', CertificateController::class)->except('destroy');
        Route::post('certificates/{certificate}/generate', [CertificateController::class, 'generate']);
        Route::get('certificates/{certificate}/download', [CertificateController::class, 'download']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('admission-statistics', [ReportController::class, 'admissionStatistics']);
            Route::get('enrolled-subjects', [ReportController::class, 'enrolledSubjects']);
            Route::get('fee-collection', [ReportController::class, 'feeCollection']);
            Route::get('student-ledger/{student}', [ReportController::class, 'studentLedger']);
        });

        // SMS Templates
        Route::apiResource('sms-templates', SmsTemplateController::class);
        Route::post('sms-templates/{smsTemplate}/preview', [SmsTemplateController::class, 'preview']);

        Route::prefix('security')->group(function () {
            Route::get('users', [SecurityController::class, 'users']);
            Route::post('users', [SecurityController::class, 'createUser']);
            Route::patch('users/{id}/deactivate', [SecurityController::class, 'deactivateUser']);
            Route::post('users/{id}/reset-password', [SecurityController::class, 'resetPassword']);
            Route::get('permissions', [SecurityController::class, 'permissions']);
            Route::get('users/{id}/permissions', [SecurityController::class, 'getUserPermissions']);
            Route::put('users/{id}/permissions', [SecurityController::class, 'updateUserPermissions']);
            Route::get('login-status', [SecurityController::class, 'loginStatus']);
            Route::get('active-sessions', [SecurityController::class, 'activeSessions']);
            Route::post('sessions/{tokenId}/force-logout', [SecurityController::class, 'forceLogout']);
            Route::get('session-summary', [SecurityController::class, 'sessionSummary']);
            Route::get('menu-shortcodes', [SecurityController::class, 'menuShortcodes']);
            Route::put('menu-shortcodes', [SecurityController::class, 'saveMenuShortcodes']);
        });

        // ── Admission Settings ────────────────────────────────────────────────────────
        Route::prefix('settings/admission')->group(function () {
            // Application Schedule
            Route::get('schedule', [MasterSettingsController::class, 'applicationScheduleIndex']);
            Route::post('schedule', [MasterSettingsController::class, 'applicationScheduleStore']);
            Route::put('schedule/{id}', [MasterSettingsController::class, 'applicationScheduleUpdate']);
            Route::delete('schedule/{id}', [MasterSettingsController::class, 'applicationScheduleDestroy']);

            // Admission Condition
            Route::get('condition', [MasterSettingsController::class, 'admissionConditionIndex']);
            Route::post('condition', [MasterSettingsController::class, 'admissionConditionStore']);
            Route::delete('condition/{id}', [MasterSettingsController::class, 'admissionConditionDestroy']);

            // Enclosure / Supporting Documents
            Route::get('enclosure', [MasterSettingsController::class, 'enclosureMasterIndex']);
            Route::post('enclosure', [MasterSettingsController::class, 'enclosureMasterStore']);
            Route::delete('enclosure/{id}', [MasterSettingsController::class, 'enclosureMasterDestroy']);
            Route::post('enclosure/bulk', [MasterSettingsController::class, 'enclosureMasterBulkStore']);

            // Fee Head
            Route::get('fee-heads', [MasterSettingsController::class, 'feeHeadIndex']);
            Route::post('fee-heads', [MasterSettingsController::class, 'feeHeadStore']);
            Route::put('fee-heads/{id}', [MasterSettingsController::class, 'feeHeadUpdate']);
            Route::delete('fee-heads/{id}', [MasterSettingsController::class, 'feeHeadDestroy']);

            // Fee Structure
            Route::get('fee-structure', [MasterSettingsController::class, 'feeStructureIndex']);
            Route::post('fee-structure', [MasterSettingsController::class, 'feeStructureStore']);
            Route::post('fee-structure/copy', [MasterSettingsController::class, 'feeStructureCopyYear']);
            Route::post('reg-fee/copy', [MasterSettingsController::class, 'registrationFeeCopyYear']);

            // Registration Fee
            Route::get('reg-fee', [MasterSettingsController::class, 'registrationFeeIndex']);
            Route::post('reg-fee', [MasterSettingsController::class, 'registrationFeeStore']);
            Route::delete('reg-fee/{id}', [MasterSettingsController::class, 'registrationFeeDestroy']);

            // Back Paper Schedule
            Route::get('back-paper-schedule', [MasterSettingsController::class, 'backPaperScheduleIndex']);
            Route::post('back-paper-schedule', [MasterSettingsController::class, 'backPaperScheduleStore']);
            Route::put('back-paper-schedule/{id}', [MasterSettingsController::class, 'backPaperScheduleUpdate']);
            Route::delete('back-paper-schedule/{id}', [MasterSettingsController::class, 'backPaperScheduleDestroy']);
        });

        // ── Course Settings ───────────────────────────────────────────────────────────
        Route::prefix('settings/course')->group(function () {
            Route::get('classes', [MasterSettingsController::class, 'classMasterIndex']);
            Route::post('classes', [MasterSettingsController::class, 'classMasterStore']);
            Route::put('classes/{id}', [MasterSettingsController::class, 'classMasterUpdate']);
            Route::delete('classes/{id}', [MasterSettingsController::class, 'classMasterDestroy']);

            Route::get('semesters', [MasterSettingsController::class, 'semesterMasterIndex']);
            Route::post('semesters', [MasterSettingsController::class, 'semesterMasterStore']);
            Route::put('semesters/{id}', [MasterSettingsController::class, 'semesterMasterUpdate']);
            Route::delete('semesters/{id}', [MasterSettingsController::class, 'semesterMasterDestroy']);

            Route::get('subjects', [MasterSettingsController::class, 'subjectMasterIndex']);
            Route::post('subjects', [MasterSettingsController::class, 'subjectMasterStore']);
            Route::put('subjects/{id}', [MasterSettingsController::class, 'subjectMasterUpdate']);
            Route::delete('subjects/{id}', [MasterSettingsController::class, 'subjectMasterDestroy']);

            Route::get('allotted-subjects', [MasterSettingsController::class, 'allottedSubjectIndex']);
            Route::post('allotted-subjects', [MasterSettingsController::class, 'allottedSubjectStore']);
            Route::delete('allotted-subjects/{id}', [MasterSettingsController::class, 'allottedSubjectDestroy']);

            Route::get('subject-papers', [MasterSettingsController::class, 'subjectPaperIndex']);
            Route::get('subject-papers/print', [MasterSettingsController::class, 'subjectPaperPrint']);
            Route::post('subject-papers', [MasterSettingsController::class, 'subjectPaperStore']);
            Route::put('subject-papers/{id}', [MasterSettingsController::class, 'subjectPaperUpdate']);
            Route::delete('subject-papers/{id}', [MasterSettingsController::class, 'subjectPaperDestroy']);

            Route::get('subject-seats', [MasterSettingsController::class, 'subjectSeatIndex']);
            Route::post('subject-seats', [MasterSettingsController::class, 'subjectSeatStore']);
            Route::delete('subject-seats/{id}', [MasterSettingsController::class, 'subjectSeatDestroy']);

            Route::get('subject-selection', [MasterSettingsController::class, 'subjectSelectionIndex']);
            Route::get('subject-selection/print', [MasterSettingsController::class, 'subjectSelectionPrint']);
            Route::post('subject-selection', [MasterSettingsController::class, 'subjectSelectionStore']);
            Route::delete('subject-selection/{id}', [MasterSettingsController::class, 'subjectSelectionDestroy']);
            Route::delete('subject-selection-group', [MasterSettingsController::class, 'subjectSelectionGroupDestroy']);
            // Groupwise subjects for application/registration dropdowns (auth)
            Route::get('subject-groups', [MasterSettingsController::class, 'subjectGroups']);

            Route::get('vocational-papers', [MasterSettingsController::class, 'vocationalPaperIndex']);
            Route::get('vocational-papers/print', [MasterSettingsController::class, 'vocationalPaperPrint']);
            Route::post('vocational-papers', [MasterSettingsController::class, 'vocationalPaperStore']);
            Route::put('vocational-papers/{id}', [MasterSettingsController::class, 'vocationalPaperUpdate']);
            Route::delete('vocational-papers/{id}', [MasterSettingsController::class, 'vocationalPaperDestroy']);
        });

        // ── Other Settings ────────────────────────────────────────────────────────────
        Route::prefix('settings')->group(function () {
            Route::get('holidays', [MasterSettingsController::class, 'holidayIndex']);
            Route::post('holidays', [MasterSettingsController::class, 'holidayStore']);
            Route::put('holidays/{id}', [MasterSettingsController::class, 'holidayUpdate']);
            Route::delete('holidays/{id}', [MasterSettingsController::class, 'holidayDestroy']);

            Route::get('print-permissions', [MasterSettingsController::class, 'printPermissionIndex']);
            Route::post('print-permissions', [MasterSettingsController::class, 'printPermissionUpdate']);

            Route::get('security-deposits', [MasterSettingsController::class, 'securityDepositIndex']);
            Route::put('security-deposits/{id}', [MasterSettingsController::class, 'securityDepositUpdate']);

            Route::get('counselling', [MasterSettingsController::class, 'counsellingIndex']);
            Route::post('counselling', [MasterSettingsController::class, 'counsellingStore']);
            Route::delete('counselling/{id}', [MasterSettingsController::class, 'counsellingDestroy']);
        });

        // payment ledger
        Route::prefix('ledger')->group(function () {
            Route::get('/', [PaymentLedgerController::class, 'index']);
            Route::get('/summary', [PaymentLedgerController::class, 'summary']);
            Route::get('/student/{studentId}', [PaymentLedgerController::class, 'studentStatement']);
            Route::get('/{id}', [PaymentLedgerController::class, 'show']);
            Route::post('/offline', [PaymentLedgerController::class, 'storeOffline']);
            Route::post('/{id}/verify', [PaymentLedgerController::class, 'verify']);
            Route::post('/{id}/refund', [PaymentLedgerController::class, 'refund']);
        });


    });

    // ── STUDENT PORTAL ────────────────────────────────────────────────────

    Route::middleware(['auth:sanctum', 'portal:student'])->prefix('student')->group(function () {

        Route::get('programs', [ProgramController::class, 'index']);


        // ── Student profile ───────────────────────────────────────────────────────
        Route::get('profile', [StudentController::class, 'myProfile']);
        Route::put('profile', [StudentController::class, 'updateProfile']);

        Route::get('programs', [ApplicationController::class, 'studentPrograms']);
        // Numeric-only so it does not swallow /student/applications, /student/profile, etc.
        Route::get('/{id}', [ApplicationController::class, 'studentShow'])->whereNumber('id');

        Route::prefix('applications')->group(function () {

            // GET  /student/applications
            Route::get('/', [ApplicationController::class, 'myApplications']);

            // POST /student/applications
            Route::post('/', [ApplicationController::class, 'store']);

            // GET  /student/applications/upgrade/self   (pre-fill upgrade form)
            Route::get('/upgrade/self', [ApplicationController::class, 'upgradeSelf']);

            // GET  /student/applications/back-paper/papers?semester_no=
            Route::get('/back-paper/papers', [ApplicationController::class, 'studentBackPaperPapers']);

            // ── Wildcard — MUST be last ───────────────────────────────────────────────

            // GET  /student/applications/{id}
            Route::get('/{id}', [ApplicationController::class, 'show'])->whereNumber('id');

            // PUT  /student/applications/{id}/part/{part}
            Route::put('/{id}/part/{part}', [ApplicationController::class, 'updatePart']);

            // POST /student/applications/{id}/submit
            Route::post('/{id}/submit', [ApplicationController::class, 'submit']);

            // Contact (mobile/email) change — OTP-gated; mobile/email are
            // locked out of the generic /part/{part} save (LocksStudentIdentity),
            // this is the only way a student can change either from the
            // application form.
            Route::post('/{id}/contact/mobile/send-otp', [ApplicationController::class, 'sendContactMobileOtp']);
            Route::post('/{id}/contact/mobile/verify', [ApplicationController::class, 'verifyContactMobileOtp']);
            Route::post('/{id}/contact/email/send-otp', [ApplicationController::class, 'sendContactEmailOtp']);
            Route::post('/{id}/contact/email/verify', [ApplicationController::class, 'verifyContactEmailOtp']);

            // POST /student/applications/{id}/documents
            Route::post('/{id}/documents', [ApplicationController::class, 'uploadStudentDocument']);

            // Back paper — student self-service save / pay / print (ownership-checked)
            // Education fee (fresh / semester_upgrade / lateral) — student
            Route::post('/{id}/pay/initiate', [ApplicationController::class, 'studentApplicationPayInitiate']);
            Route::post('/{id}/pay/verify', [ApplicationController::class, 'studentApplicationPayVerify']);
            Route::post('/{id}/pay/failed', [ApplicationController::class, 'studentApplicationPayFailed']);

            Route::put('/{id}/back-paper', [ApplicationController::class, 'studentSaveBackPaperSelection']);
            Route::post('/{id}/back-paper/pay/initiate', [ApplicationController::class, 'studentBackPaperPayInitiate']);
            Route::post('/{id}/back-paper/pay/verify', [ApplicationController::class, 'studentBackPaperPayVerify']);
            Route::post('/{id}/back-paper/pay/failed', [ApplicationController::class, 'studentBackPaperPayFailed']);
            Route::get('/{id}/back-paper/print', [ApplicationController::class, 'studentPrintBackPaperForm']);
            Route::get('/{id}/back-paper/receipt', [ApplicationController::class, 'studentDownloadBackPaperReceipt']);
        });



        // ── Program subjects (used by Part 6 subject selection) ───────────────────
        Route::get('programs/{programId}/subjects', [ProgramController::class, 'subjects']);
        // Real paper code/name for those subjects — a subject can carry more
        // than one paper under the DDU system, so this is grouped by subject_id.
        Route::get('programs/{programId}/subject-papers', [ProgramController::class, 'subjectPapers']);

        Route::get('registration-status', [RegistrationController::class, 'studentPortalStatus']);

        // Student Registration Dashboard (identity card + activity rows)
        Route::get('registration/dashboard', [RegistrationController::class, 'studentRegistrationDashboard']);

        Route::get('registration/pending', [StudentRegistrationController::class, 'studentPending']);
        Route::post('registration/pay', [StudentRegistrationController::class, 'payPending']);
    });

    // (University portal removed — this is a college system, not a
    // university system; 'university' was dropped from the users.portal
    // enum by migration. This whole group was unreachable — its middleware
    // could never match, and one of its two routes pointed at a
    // StudentController::universityView() method that didn't exist.)
});
