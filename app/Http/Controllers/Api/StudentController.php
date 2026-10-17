<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Concerns\LocksStudentIdentity;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class StudentController extends Controller
{
    use LocksStudentIdentity;

    public function index(Request $request): JsonResponse
    {
        $query = Student::with(['currentAdmission.program', 'organization'])
            ->where('organization_id', $request->user()->organization_id);

        // Filters
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($w) use ($q) {
                $w->where('personal_info->first_name', 'ilike', "%{$q}%")
                  ->orWhere('personal_info->last_name', 'ilike', "%{$q}%")
                  ->orWhere('enrollment_no', 'ilike', "%{$q}%")
                  ->orWhere('mobile', 'like', "%{$q}%");
            });
        }

        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('program_id')) {
            $query->whereHas('currentAdmission', fn($q) => $q->where('program_id', $request->program_id));
        }
        // A student has no session-year column of their own — the session
        // is on their current admission (same source AdmissionController::
        // index() filters on), so this list is scoped the same way.
        if ($request->filled('academic_year')) {
            $query->whereHas('currentAdmission', fn($q) => $q->where('academic_year', $request->academic_year));
        }

        $students = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

        return response()->json($students);
    }

    public function show(Student $student): JsonResponse
    {
        $student->load([
            'currentAdmission.program',
            'feeReceipts' => fn($q) => $q->latest()->take(5),
        ]);

        return response()->json($student);
    }

    /**
     * GET /student/profile
     * Self-service profile used by the student portal's "Registered Details
     * (locked)" card. `students` has first_name/middle_name/last_name (no
     * name/father_name/mother_name columns), so those identity fields come
     * from the direct_registrations snapshot — same source and matching
     * pattern used by parseApp()/studentRegistrationDashboard() elsewhere.
     * This route previously pointed at a controller method that didn't
     * exist at all (Call to undefined method), which is why every load of
     * this card silently failed.
     */
    // GET /student/education-history — semesters and back papers paid for.
    public function myEducationHistory(Request $request): JsonResponse
    {
        return response()->json(['data' => \App\Support\StudentSemesters::history($request->user())]);
    }

    public function myProfile(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        // A first-time applicant has no students row until their fee
        // receipt is verified — fall back to the registration and the
        // latest application so the dashboard is never blank.
        $student = Student::flat(DB::table('students')->where('user_id', $userId)->first());

        $mobile = $student->mobile ?? $request->user()->mobile ?? null;
        $registration = DB::table('direct_registrations')
            ->where(function ($q) use ($userId, $mobile) {
                $q->where('user_id', $userId);
                if (!empty($mobile)) {
                    $q->orWhereRaw('RIGHT(mobile, 10) = RIGHT(?, 10)', [$mobile]);
                }
            })
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $app = DB::table('student_applications as sa')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'sa.direct_registration_id')
            ->where(fn($q) => $q->where('sa.user_id', $userId)->orWhere('dr.user_id', $userId))
            ->whereNull('sa.deleted_at')
            ->orderByDesc('sa.created_at')
            ->select('sa.*')
            ->first();

        if (!$student && !$registration && !$app) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $program = DB::table('programs')->where('id', $app->program_id ?? $registration->program_id ?? 0)->first();
        $appReceiptId = $app ? \App\Support\Payments::applicationFeeReceiptId((int) $app->id) : null;
        $receipt = $appReceiptId
            ? DB::table('fee_receipts')->where('id', $appReceiptId)->first()
            : null;

        // Photo: students table first, else the Part 7 upload.
        $photoPath = $student->photo_path ?? null;
        if (!$photoPath && $app) {
            $photoPath = DB::table('student_application_documents')
                ->where('application_id', $app->id)
                ->where('document_type', 'photo')
                ->orderByDesc('id')
                ->value('path');
        }
        $photoUrl = null;
        if ($photoPath) {
            if (preg_match('#^https?://#', $photoPath)) {
                $photoUrl = $photoPath;
            } else {
                try {
                    $photoUrl = Storage::disk('supabase')->exists($photoPath)
                        ? Storage::disk('supabase')->url($photoPath)
                        : Storage::disk('public')->url($photoPath);
                } catch (\Throwable $e) {
                    $photoUrl = Storage::disk('public')->url($photoPath);
                }
            }
        }

        // Address: students table, else the application's Part 2 permanent address.
        $join = fn(array $parts) => trim(implode(', ', array_filter(array_map(fn($v) => trim((string) $v), $parts))));
        $address = $join([
            $student->permanent_address ?? null, $student->permanent_city ?? null,
            $student->permanent_district ?? null, $student->permanent_state ?? null,
            $student->permanent_pin ?? null,
        ]);
        if ($address === '' && $app && !empty($app->part_2)) {
            $p2 = is_string($app->part_2) ? (json_decode($app->part_2, true) ?: []) : (array) $app->part_2;
            $address = $join([
                $p2['perm_house_no'] ?? null, $p2['perm_mohalla'] ?? null, $p2['perm_post_office'] ?? null,
                $p2['perm_district'] ?? null, $p2['perm_state'] ?? null, $p2['perm_pin_code'] ?? null,
            ]);
        }

        $fullName = trim(implode(' ', array_filter([
            $student->first_name ?? null,
            $student->middle_name ?? null,
            $student->last_name ?? null,
        ])));
        $name = $registration->name ?? ($fullName !== '' ? $fullName : null);

        return response()->json(['data' => [
            'id'                  => $student->id ?? null,
            'name'                => $name,
            'full_name'           => $name,
            'first_name'          => $student->first_name ?? null,
            'middle_name'         => $student->middle_name ?? null,
            'last_name'           => $student->last_name ?? null,
            'father_name'         => $registration->father_name ?? null,
            'mother_name'         => $registration->mother_name ?? null,
            'date_of_birth'       => $student->date_of_birth ?? $registration->dob ?? null,
            'gender'              => $student->gender ?? $registration->gender ?? null,
            'category'            => $student->category ?? $registration->category ?? null,
            'religion'            => $student->religion ?? $registration->religion ?? null,
            'nationality'         => $student->nationality ?? $registration->nationality ?? null,
            'aadhar_no'           => $student->aadhar_no ?? $registration->aadhar_no ?? null,
            'abc_id'              => $student->abc_id ?? $registration->abc_id ?? null,
            'ddurn'               => $student->ddurn ?? $registration->ddurn_no ?? null,
            'family_id'           => $student->family_id ?? $registration->family_id ?? null,
            'mobile'              => $student->mobile ?? $registration->mobile ?? null,
            'alternate_mobile'    => $student->alternate_mobile ?? null,
            'email'               => $student->email ?? $registration->email ?? null,
            'whatsapp_no'         => $student->whatsapp_no ?? null,
            'permanent_address'   => $student->permanent_address ?? null,
            'permanent_city'      => $student->permanent_city ?? null,
            'permanent_district'  => $student->permanent_district ?? null,
            'permanent_state'     => $student->permanent_state ?? null,
            'permanent_pin'       => $student->permanent_pin ?? null,
            'enrollment_no'       => $student->enrollment_no ?? null,
            'student_uid'         => $student->student_uid ?? null,
            'photo_path'          => $student->photo_path ?? null,
            'photo_url'           => $photoUrl,
            'address'             => $address !== '' ? $address : null,
            // Dashboard summary
            'registration_no'     => $registration->registration_no ?? null,
            'application_no'      => $app->application_no ?? null,
            'application_status'  => $app->status ?? null,
            'fee_receipt_no'      => $receipt->receipt_no ?? null,
            'session'             => $app->academic_year ?? $registration->session_year ?? null,
            'semester_no'         => $app->semester_no ?? null,
            'program_name'        => $program->name ?? null,
            'program_short_name'  => $program->short_name ?? null,
            'samarth_code'        => $program->samarth_code ?? null,
            'has_student_record'  => (bool) $student,
            'has_registration_snapshot' => (bool) $registration,
        ]]);
    }

    /**
     * PUT /student/profile
     * Students may only update non-identity contact fields here — name,
     * DOB, gender, category, aadhar/ABC/DDURN/family id, and permanent
     * address stay locked to the registration snapshot to keep ERP data
     * integrity (same locked-field list as the application form).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $student = Student::flat(DB::table('students')->where('user_id', $request->user()->id)->first());
        if (!$student) {
            return response()->json(['message' => 'Student profile not found.'], 404);
        }

        $clean = $this->stripLockedIdentity($request->all(), $request->user()->id);

        $allowed = array_intersect_key($clean, array_flip([
            'alternate_mobile', 'whatsapp_no', 'correspondence_address',
            'correspondence_city', 'correspondence_district',
            'correspondence_state', 'correspondence_pin', 'same_as_permanent',
        ]));

        if (empty($allowed)) {
            return response()->json(['message' => 'No editable fields were provided.'], 422);
        }

        $allowed['updated_at'] = now();
        Student::groupedUpdate($student->id, $allowed);

        return response()->json(['message' => 'Profile updated.']);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name'         => 'required|string|max:100',
            'middle_name'        => 'nullable|string|max:100',
            'last_name'          => 'required|string|max:100',
            'gender'             => 'required|in:male,female,transgender',
            'date_of_birth'      => 'required|date|before:today',
            'category'           => 'required|in:general,obc,sc,st,ews',
            'mobile'             => 'required|string|size:10|unique:students,mobile',
            'email'              => 'nullable|email|unique:students,email',
            'aadhar_no'          => 'nullable|string|size:12',
            'permanent_address'  => 'required|string',
            'permanent_city'     => 'required|string',
            'permanent_district' => 'required|string',
            'permanent_state'    => 'required|string',
            'permanent_pin'      => 'required|string|size:6',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;

        $student = DB::transaction(function () use ($validated, $request) {
            $student = Student::create($validated);

            // Create login account for student
            $user = User::create([
                'organization_id' => $validated['organization_id'],
                'name'            => "{$validated['first_name']} {$validated['last_name']}",
                'mobile'          => $validated['mobile'],
                'email'           => $validated['email'] ?? null,
                'password'        => Hash::make($validated['mobile']), // default password = mobile
                'portal'          => 'student',
                'is_active'       => true,
            ]);
            $user->assignRole('student');
            $student->update(['user_id' => $user->id]);

            return $student;
        });

        return response()->json($student->load('user'), 201);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'first_name'         => 'sometimes|string|max:100',
            'middle_name'        => 'nullable|string|max:100',
            'last_name'          => 'sometimes|string|max:100',
            'mobile'             => "sometimes|string|size:10|unique:students,mobile,{$student->id}",
            'email'              => "nullable|email|unique:students,email,{$student->id}",
            'permanent_address'  => 'sometimes|string',
            'permanent_city'     => 'sometimes|string',
            'bank_name'          => 'nullable|string',
            'bank_ifsc'          => 'nullable|string|size:11',
            'bank_account_no'    => 'nullable|string',
        ]);

        $student->update($validated);

        return response()->json($student);
    }

    public function uploadPhoto(Request $request, Student $student): JsonResponse
    {
        $request->validate(['photo' => 'required|image|mimes:jpg,jpeg,png|max:500']);

        if ($student->photo_path) {
            Storage::disk('public')->delete($student->photo_path);
        }

        $path = $request->file('photo')->store("students/{$student->id}/photos", 'public');
        $student->update(['photo_path' => $path]);

        return response()->json(['photo_url' => Storage::url($path)]);
    }

    public function uploadSignature(Request $request, Student $student): JsonResponse
    {
        $request->validate(['signature' => 'required|image|mimes:jpg,jpeg,png|max:200']);

        if ($student->signature_path) {
            Storage::disk('public')->delete($student->signature_path);
        }

        $path = $request->file('signature')->store("students/{$student->id}/signatures", 'public');
        $student->update(['signature_path' => $path]);

        return response()->json(['signature_url' => Storage::url($path)]);
    }

    public function blockUnblock(Request $request, Student $student): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:block,unblock',
            'reason' => 'required_if:action,block|string|max:500',
        ]);

        $student->update([
            'is_blocked'   => $request->action === 'block',
            'block_reason' => $request->action === 'block' ? $request->reason : null,
            'status'       => $request->action === 'block' ? 'blocked' : 'active',
        ]);

        // Also update user account
        $student->user?->update(['is_active' => $request->action !== 'block']);

        return response()->json(['message' => "Student {$request->action}ed successfully."]);
    }

    public function ledger(Student $student): JsonResponse
    {
        $receipts = $student->feeReceipts()
            ->with(['admission.program'])
            ->orderBy('receipt_date', 'desc')
            ->get();

        $totalPaid = $receipts->where('status', 'active')->sum('net_amount');

        return response()->json([
            'student'    => $student->only(['id', 'full_name', 'enrollment_no', 'mobile']),
            'receipts'   => $receipts,
            'total_paid' => $totalPaid,
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $stats = [
            'total'       => Student::where('organization_id', $orgId)->count(),
            'active'      => Student::where('organization_id', $orgId)->where('status', 'active')->count(),
            'blocked'     => Student::where('organization_id', $orgId)->where('is_blocked', true)->count(),
            'by_category' => Student::where('organization_id', $orgId)
                                ->selectRaw("personal_info->>'category' as category, count(*) as count")
                                ->groupBy(DB::raw("personal_info->>'category'"))
                                ->pluck('count', 'category'),
            'by_gender'   => Student::where('organization_id', $orgId)
                                ->selectRaw("personal_info->>'gender' as gender, count(*) as count")
                                ->groupBy(DB::raw("personal_info->>'gender'"))
                                ->pluck('count', 'gender'),
        ];

        return response()->json($stats);
    }
}
