<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Student::with(['currentAdmission.program', 'organization'])
            ->where('organization_id', $request->user()->organization_id);

        // Filters
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($w) use ($q) {
                $w->where('first_name', 'ilike', "%{$q}%")
                  ->orWhere('last_name', 'ilike', "%{$q}%")
                  ->orWhere('enrollment_no', 'ilike', "%{$q}%")
                  ->orWhere('mobile', 'like', "%{$q}%");
            });
        }

        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('program_id')) {
            $query->whereHas('currentAdmission', fn($q) => $q->where('program_id', $request->program_id));
        }

        $students = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

        return response()->json($students);
    }

    public function show(Student $student): JsonResponse
    {
        $student->load([
            'currentAdmission.program',
            'documents',
            'feeReceipts' => fn($q) => $q->latest()->take(5),
        ]);

        return response()->json($student);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name'         => 'required|string|max:100',
            'middle_name'        => 'nullable|string|max:100',
            'last_name'          => 'required|string|max:100',
            'gender'             => 'required|in:male,female,other',
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
                                ->selectRaw('category, count(*) as count')
                                ->groupBy('category')
                                ->pluck('count', 'category'),
            'by_gender'   => Student::where('organization_id', $orgId)
                                ->selectRaw('gender, count(*) as count')
                                ->groupBy('gender')
                                ->pluck('count', 'gender'),
        ];

        return response()->json($stats);
    }
}
