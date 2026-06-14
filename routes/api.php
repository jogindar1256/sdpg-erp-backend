<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FeeHeadController;
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
use Illuminate\Support\Facades\Route;

// ─── Public Routes ─────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('student/login', [AuthController::class, 'studentLogin']);
    Route::post('student/register', [StudentRegistrationController::class, 'register']);      // remove the extra "auth/"
    Route::post('student/check-mobile', [StudentRegistrationController::class, 'checkMobile']);  // remove the extra "auth/"
});


// IFSC lookup (public)
Route::get(
    'bank/ifsc/{code}',
    fn(string $code) =>
    response()->json(\App\Models\BankBranch::where('ifsc_code', strtoupper($code))->firstOrFail())
);

// ─── Authenticated Routes ───────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    // ── COLLEGE PORTAL ────────────────────────────────────────────────────
    Route::middleware('portal:college,super_admin')->group(function () {

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
        Route::get('applications', [ApplicationController::class, 'index']);
        Route::get('applications/{application}', [ApplicationController::class, 'show']);
        Route::post('applications/{application}/approve', [ApplicationController::class, 'approve']);
        Route::post('applications/{application}/reject', [ApplicationController::class, 'reject']);
        Route::get('applications/statistics', [ApplicationController::class, 'statistics']);

        // Admissions
        Route::apiResource('admissions', AdmissionController::class)->except(['store', 'destroy']);
        Route::post('admissions/{admission}/verify', [AdmissionController::class, 'verify']);
        Route::post('admissions/{admission}/cancel', [AdmissionController::class, 'cancel']);
        Route::get('admissions/statistics', [AdmissionController::class, 'statistics']);
        Route::get('admissions/upgrade-list', [AdmissionController::class, 'upgradeList']);
        Route::post('admissions/{admission}/upgrade', [AdmissionController::class, 'upgrade']);
        Route::get('admissions/biometrics', [AdmissionController::class, 'biometrics']);
        Route::patch('admissions/biometrics/{student}', [AdmissionController::class, 'updateBiometric']);
        Route::get('admissions/education-fee', [AdmissionController::class, 'educationFee']);
        Route::get('admissions/ledger', [AdmissionController::class, 'ledger']);
        Route::get('admissions/statistics', [AdmissionController::class, 'statistics']);
        Route::get('admissions/subject-statistics', [AdmissionController::class, 'subjectStatistics']);

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

            // Student Status lookup
            Route::get('student-status', [RegistrationController::class, 'studentStatus']);

            // Subject Group
            Route::get('subject-groups', [RegistrationController::class, 'subjectGroupIndex']);
            Route::post('subject-groups', [RegistrationController::class, 'subjectGroupStore']);
            Route::delete('subject-groups/{id}', [RegistrationController::class, 'subjectGroupDestroy']);
            Route::post('subject-groups/auto-assign', [RegistrationController::class, 'subjectGroupAutoAssign']);

            // Stats
            Route::get('stats', [RegistrationController::class, 'stats']);
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

        // Amendments
        Route::get('amendments', [AmendmentController::class, 'index']);
        Route::get('amendments/{amendment}', [AmendmentController::class, 'show']);
        Route::post('amendments/{amendment}/approve', [AmendmentController::class, 'approve']);
        Route::post('amendments/{amendment}/reject', [AmendmentController::class, 'reject']);

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

            // Enclosure / Supporting Documents
            Route::get('enclosure', [MasterSettingsController::class, 'enclosureMasterIndex']);
            Route::post('enclosure', [MasterSettingsController::class, 'enclosureMasterStore']);
            Route::delete('enclosure/{id}', [MasterSettingsController::class, 'enclosureMasterDestroy']);

            // Fee Head
            Route::get('fee-heads', [MasterSettingsController::class, 'feeHeadIndex']);
            Route::post('fee-heads', [MasterSettingsController::class, 'feeHeadStore']);
            Route::put('fee-heads/{id}', [MasterSettingsController::class, 'feeHeadUpdate']);
            Route::delete('fee-heads/{id}', [MasterSettingsController::class, 'feeHeadDestroy']);

            // Fee Structure
            Route::get('fee-structure', [MasterSettingsController::class, 'feeStructureIndex']);
            Route::post('fee-structure', [MasterSettingsController::class, 'feeStructureStore']);
            Route::post('fee-structure/copy', [MasterSettingsController::class, 'feeStructureCopyYear']);

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
            Route::post('subject-papers', [MasterSettingsController::class, 'subjectPaperStore']);
            Route::delete('subject-papers/{id}', [MasterSettingsController::class, 'subjectPaperDestroy']);

            Route::get('subject-seats', [MasterSettingsController::class, 'subjectSeatIndex']);
            Route::post('subject-seats', [MasterSettingsController::class, 'subjectSeatStore']);
            Route::delete('subject-seats/{id}', [MasterSettingsController::class, 'subjectSeatDestroy']);

            Route::get('subject-selection', [MasterSettingsController::class, 'subjectSelectionIndex']);
            Route::post('subject-selection', [MasterSettingsController::class, 'subjectSelectionStore']);
            Route::delete('subject-selection/{id}', [MasterSettingsController::class, 'subjectSelectionDestroy']);

            Route::get('vocational-papers', [MasterSettingsController::class, 'vocationalPaperIndex']);
            Route::post('vocational-papers', [MasterSettingsController::class, 'vocationalPaperStore']);
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

    });

    // ── STUDENT PORTAL ────────────────────────────────────────────────────
    Route::middleware('portal:student')->prefix('student')->group(function () {

        // Profile
        Route::get('profile', [StudentController::class, 'myProfile']);
        Route::put('profile', [StudentController::class, 'updateProfile']);

        // Applications
        Route::get('applications', [ApplicationController::class, 'myApplications']);
        Route::post('applications', [ApplicationController::class, 'store']);
        Route::get('applications/{application}', [ApplicationController::class, 'show']);
        Route::put('applications/{application}/part/{part}', [ApplicationController::class, 'updatePart']);
        Route::post('applications/{application}/submit', [ApplicationController::class, 'submit']);

        // Documents
        Route::post('documents', [\App\Http\Controllers\Api\DocumentController::class, 'upload']);
        Route::get('documents', [\App\Http\Controllers\Api\DocumentController::class, 'myDocuments']);

        // Fee Receipts
        Route::get('fee-receipts', [FeeReceiptController::class, 'myReceipts']);
        Route::get('fee-receipts/{r}/download', [FeeReceiptController::class, 'download']);

        // Exam
        Route::get('examinations', [ExaminationController::class, 'available']);
        Route::post('exam-applications', [ExaminationController::class, 'applyExam']);
        Route::get('exam-applications/my', [ExaminationController::class, 'myApplications']);
        Route::get('exam-applications/{app}/admit-card', [ExaminationController::class, 'myAdmitCard']);

        // Certificates
        Route::get('certificates', [CertificateController::class, 'myCertificates']);
        Route::get('certificates/{c}/download', [CertificateController::class, 'download']);

        // Amendments
        Route::post('amendments', [AmendmentController::class, 'store']);
        Route::get('amendments', [AmendmentController::class, 'myAmendments']);
    });

    // ── UNIVERSITY PORTAL ────────────────────────────────────────────────
    Route::middleware('portal:university')->prefix('university')->group(function () {
        Route::get('colleges', [OrganizationController::class, 'list']);
        Route::get('students', [StudentController::class, 'universityView']);
        Route::get('admissions', [AdmissionController::class, 'universityView']);
        Route::get('reports/consolidated', [ReportController::class, 'consolidated']);
    });
});
