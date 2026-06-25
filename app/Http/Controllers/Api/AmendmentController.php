<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AmendmentController extends Controller
{
    // ══════════════════════════════════════════════════════════════
    // SHARED HELPER — lookup student by any identifier
    // ══════════════════════════════════════════════════════════════
    private function findStudent(string $key): ?object
    {
        return DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->leftJoin('applications as ap', function ($j) {
                $j->on('ap.student_id', 's.id')->where('ap.form_type', 'Fresh');
            })
            ->where(function ($q) use ($key) {
                $q->where('a.roll_no',       $key)
                  ->orWhere('a.enrollment_no', $key)
                  ->orWhere('a.account_no',    $key)
                  ->orWhere('s.mobile',        $key)
                  ->orWhere('s.aadhar_no',     $key)
                  ->orWhere('ap.application_no', $key)
                  ->orWhere('ap.reg_no',        $key)
                  ->orWhere('s.id',             $key);
            })
            ->select(
                'a.id as admission_id', 'a.roll_no', 'a.enrollment_no', 'a.account_no',
                'a.semester_no', 'a.status as admission_status',
                's.id as student_id', 's.name', 's.father_name', 's.mother_name',
                's.spouse_name', 's.mobile', 's.gender', 's.category', 's.dob',
                's.aadhar_no', 's.abc_id', 's.ddurn', 's.address',
                'p.short_name as class', 'p.full_name', 'p.level',
                'ap.application_no', 'ap.reg_no', 'ap.status as app_status'
            )
            ->first();
    }

    private function refNo(string $prefix): string
    {
        return $prefix . date('Y') . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    // ══════════════════════════════════════════════════════════════
    // 1. SEARCH STUDENT
    // ══════════════════════════════════════════════════════════════
    public function search(Request $req)
    {
        $v = Validator::make($req->all(), ['query' => 'required|string|min:2']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $k = $req->query;
        $rows = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->leftJoin('applications as ap', function ($j) {
                $j->on('ap.student_id', 's.id')->where('ap.form_type', 'Fresh');
            })
            ->leftJoin('semester_registrations as sr', function ($j) use ($req) {
                $j->on('sr.admission_id', 'a.id')
                  ->where('sr.session_year', $req->session_year ?? date('Y').'-'.(date('Y')+1));
            })
            ->leftJoin('fee_receipts as fr', 'fr.admission_id', 'a.id')
            ->where(function ($q) use ($k) {
                $q->where('a.roll_no',         'ilike', "%{$k}%")
                  ->orWhere('s.name',           'ilike', "%{$k}%")
                  ->orWhere('s.father_name',    'ilike', "%{$k}%")
                  ->orWhere('s.mother_name',    'ilike', "%{$k}%")
                  ->orWhere('s.mobile',         'ilike', "%{$k}%")
                  ->orWhere('s.aadhar_no',      'ilike', "%{$k}%")
                  ->orWhere('a.enrollment_no',  'ilike', "%{$k}%")
                  ->orWhere('ap.application_no','ilike', "%{$k}%")
                  ->orWhere('ap.reg_no',        'ilike', "%{$k}%");
            })
            ->select(
                'a.id as admission_id', 'a.roll_no', 'a.enrollment_no', 'a.account_no',
                'a.semester_no', 'a.status as admission_status',
                's.id as student_id', 's.name', 's.father_name', 's.mother_name',
                's.mobile', 's.gender', 's.category', 's.aadhar_no', 's.abc_id', 's.ddurn',
                'p.short_name as class', 'p.level',
                'ap.application_no', 'ap.reg_no', 'ap.status as app_status',
                'sr.status as reg_status',
                DB::raw('MAX(fr.receipt_no) as final_receipt_no'),
                DB::raw('MAX(fr.status) as fee_status')
            )
            ->groupBy(
                'a.id','a.roll_no','a.enrollment_no','a.account_no','a.semester_no','a.status',
                's.id','s.name','s.father_name','s.mother_name','s.mobile','s.gender',
                's.category','s.aadhar_no','s.abc_id','s.ddurn',
                'p.short_name','p.level',
                'ap.application_no','ap.reg_no','ap.status','sr.status'
            )
            ->limit(20)
            ->get();

        return response()->json(['count' => count($rows), 'data' => $rows]);
    }

    // ══════════════════════════════════════════════════════════════
    // 2. MODIFY STUDENT DATA
    // ══════════════════════════════════════════════════════════════
    public function modifyGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student) return response()->json(['message' => 'Student not found.'], 404);

        // Return full student profile
        $full = DB::table('students')->find($student->student_id);
        return response()->json(array_merge((array)$student, ['full_profile' => $full]));
    }

    public function modifyUpdate(Request $req)
    {
        $v = Validator::make($req->all(), [
            'student_id' => 'required|exists:students,id',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $allowed = [
            'name','name_hindi','father_name','father_name_hindi',
            'mother_name','mother_name_hindi','dob','marital_status','spouse_name',
            'spouse_name_hindi','address','religion','police_station','nationality',
            'post','blood_group','district','state','sub_district',
            'bank_name','caste_cert_no','caste_cert_date',
            // Identity fields
            'abc_id','enrollment_no','university_roll_no','aadhar_no','ddurn','family_id',
        ];

        $data = array_filter($req->only($allowed), fn($v) => $v !== null);
        $data['updated_at'] = now();

        DB::table('students')->where('id', $req->student_id)->update($data);

        // Log amendment
        DB::table('amendment_logs')->insert([
            'student_id'   => $req->student_id,
            'action_type'  => 'ModifyData',
            'changed_data' => json_encode($data),
            'modified_by'  => $req->modified_by ?? 'staff',
            'ref_no'       => $this->refNo('MD'),
            'status'       => 'Pending',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => 'Data updated and sent for approval.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 3. SUBJECT CHANGE
    // ══════════════════════════════════════════════════════════════
    public function subjectChangeGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student) return response()->json(['message' => 'Student not found.'], 404);

        // Current subjects
        $subjects = DB::table('application_subjects as asub')
            ->join('subjects as sub', 'sub.id', 'asub.subject_id')
            ->where('asub.admission_id', $student->admission_id)
            ->select('asub.*', 'sub.name as subject_name')
            ->get();

        return response()->json(array_merge((array)$student, ['current_subjects' => $subjects]));
    }

    public function subjectChangeStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id'     => 'required|exists:admissions,id',
            'new_subjects'     => 'required|array',
            'drop_subject_id'  => 'nullable|exists:subjects,id',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $refNo = $this->refNo('SUB');

        DB::table('amendment_logs')->insert([
            'student_id'   => DB::table('admissions')->find($req->admission_id)?->student_id,
            'admission_id' => $req->admission_id,
            'action_type'  => 'SubjectChange',
            'changed_data' => json_encode([
                'new_subjects'    => $req->new_subjects,
                'drop_subject_id' => $req->drop_subject_id,
            ]),
            'modified_by'  => $req->modified_by ?? 'staff',
            'ref_no'       => $refNo,
            'status'       => 'Pending',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => 'Subject change request queued.', 'ref_no' => $refNo]);
    }

    // ══════════════════════════════════════════════════════════════
    // 4. UPDATE MOBILE NO
    // ══════════════════════════════════════════════════════════════
    public function updateMobileGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student) return response()->json(['message' => 'Student not found.'], 404);

        return response()->json($student);
    }

    public function updateMobileStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'student_id'  => 'required|exists:students,id',
            'new_mobile'  => 'required|digits:10',
            'without_otp' => 'boolean',
            'otp'         => 'required_if:without_otp,false|nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('students')->where('id', $req->student_id)
            ->update(['mobile' => $req->new_mobile, 'updated_at' => now()]);

        DB::table('amendment_logs')->insert([
            'student_id'  => $req->student_id,
            'action_type' => 'MobileUpdate',
            'changed_data'=> json_encode(['new_mobile' => $req->new_mobile, 'without_otp' => $req->without_otp]),
            'modified_by' => $req->modified_by ?? 'staff',
            'ref_no'      => $this->refNo('MOB'),
            'status'      => 'Pending',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json(['message' => 'Mobile number updated. Sent for approval.']);
    }

    public function sendOtp(Request $req)
    {
        $v = Validator::make($req->all(), ['mobile' => 'required|digits:10']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        // In production: integrate with SMS gateway
        $otp = rand(100000, 999999);
        // Store OTP in cache for verification (cache key: otp_{mobile})
        // Cache::put("otp_{$req->mobile}", $otp, now()->addMinutes(10));

        return response()->json(['message' => 'OTP sent.', 'debug_otp' => $otp]); // remove debug_otp in prod
    }

    // ══════════════════════════════════════════════════════════════
    // 5. UPDATE TC & MIGRATION
    // ══════════════════════════════════════════════════════════════
    public function updateTcGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student) return response()->json(['message' => 'Student not found.'], 404);

        $tc  = DB::table('application_tc')
            ->whereIn('application_id',
                DB::table('applications')->where('student_id', $student->student_id)->pluck('id')
            )->first();
        $mig = DB::table('application_migration')
            ->whereIn('application_id',
                DB::table('applications')->where('student_id', $student->student_id)->pluck('id')
            )->first();

        return response()->json(array_merge((array)$student, ['tc' => $tc, 'migration' => $mig]));
    }

    public function updateTcStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id' => 'required|exists:admissions,id',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $appId = DB::table('applications')
            ->where('student_id', DB::table('admissions')->find($req->admission_id)?->student_id)
            ->value('id');

        if ($req->tc_data) {
            DB::table('application_tc')->updateOrInsert(
                ['application_id' => $appId],
                array_merge($req->tc_data, ['updated_at' => now(), 'created_at' => now()])
            );
        }
        if ($req->migration_data) {
            DB::table('application_migration')->updateOrInsert(
                ['application_id' => $appId],
                array_merge($req->migration_data, ['updated_at' => now(), 'created_at' => now()])
            );
        }

        DB::table('amendment_logs')->insert([
            'student_id'  => DB::table('admissions')->find($req->admission_id)?->student_id,
            'action_type' => 'TCMigrationUpdate',
            'modified_by' => $req->modified_by ?? 'staff',
            'ref_no'      => $this->refNo('TC'),
            'status'      => 'Completed',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json(['message' => 'TC/Migration updated.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 6. UPDATE PAPER FOR STUDENT
    // ══════════════════════════════════════════════════════════════
    public function updatePaperIndex(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'  => 'required|exists:programs,id',
            'semester_no' => 'required|string',
            'paper_type'  => 'nullable|string',
            'subject_id'  => 'nullable|exists:subjects,id',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        // Get all papers for program+semester
        $papers = DB::table('subject_papers as sp')
            ->join('subjects as sub', 'sub.id', 'sp.subject_id')
            ->when($req->subject_id, fn($q) => $q->where('sp.subject_id', $req->subject_id))
            ->where('sp.semester_no', $req->semester_no)
            ->select('sp.*', 'sub.name as subject_name')
            ->get();

        // Students in this program/semester
        $students = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->where('a.program_id', $req->program_id)
            ->where('a.semester_no', $req->semester_no)
            ->select('a.id as admission_id', 'a.roll_no', 's.name', 's.father_name', 'a.enrollment_no')
            ->orderBy('a.roll_no')
            ->get();

        return response()->json(compact('papers', 'students'));
    }

    public function updatePaperStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'updates' => 'required|array',
            'updates.*.admission_id' => 'required|exists:admissions,id',
            'updates.*.old_paper_id' => 'required|exists:subject_papers,id',
            'updates.*.new_paper_id' => 'required|exists:subject_papers,id',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        foreach ($req->updates as $upd) {
            DB::table('application_subjects')
                ->where('admission_id', $upd['admission_id'])
                ->where('paper_id', $upd['old_paper_id'])
                ->update(['paper_id' => $upd['new_paper_id'], 'updated_at' => now()]);
        }

        return response()->json(['message' => count($req->updates) . ' paper(s) updated.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 7. DOWNLOAD DOCUMENTS
    // ══════════════════════════════════════════════════════════════
    public function downloadDocuments(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'    => 'required|exists:programs,id',
            'semester_type' => 'nullable|in:Odd,Even,All',
            'semester_no'   => 'nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $students = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->leftJoin('applications as ap', function ($j) {
                $j->on('ap.student_id', 's.id')->where('ap.form_type', 'Fresh');
            })
            ->where('a.program_id', $req->program_id)
            ->when($req->semester_no && $req->semester_no !== 'All',
                fn($q) => $q->where('a.semester_no', $req->semester_no))
            ->select(
                'a.id as admission_id', 'a.roll_no', 'a.account_no', 'a.semester_no',
                's.name', 's.mobile',
                'ap.application_no'
            )
            ->orderBy('a.roll_no')
            ->get();

        return response()->json($students);
    }

    // ══════════════════════════════════════════════════════════════
    // 8. IMPORT / EXPORT DATA
    // ══════════════════════════════════════════════════════════════
    public function importData(Request $req)
    {
        $v = Validator::make($req->all(), [
            'session_year' => 'nullable|string',
            'program_id'   => 'nullable|exists:programs,id',
            'semester_no'  => 'nullable|string',
            'table_type'   => 'required|in:Registration,Admission,Examination',
            'fields'       => 'required|array|min:1',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        // Build dynamic query based on selected fields
        $fieldMap = [
            'Registration No'        => 'a.roll_no',
            'Application No'         => 'ap.application_no',
            'University Roll No'     => 'a.enrollment_no',
            'Enrolment No'           => 'a.account_no',
            'Student Name English'   => 's.name',
            'Father Name English'    => 's.father_name',
            'Mother Name English'    => 's.mother_name',
            'Date of Birth'          => 's.dob',
            'Category'               => 's.category',
            'Gander'                 => 's.gender',
        ];

        $selects = [];
        foreach ($req->fields as $field) {
            if (isset($fieldMap[$field])) $selects[] = "{$fieldMap[$field]} as \"{$field}\"";
        }
        if (empty($selects)) $selects = ['s.name'];

        $data = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->leftJoin('applications as ap', function ($j) {
                $j->on('ap.student_id', 's.id')->where('ap.form_type', 'Fresh');
            })
            ->when($req->program_id,  fn($q) => $q->where('a.program_id',  $req->program_id))
            ->when($req->semester_no, fn($q) => $q->where('a.semester_no', $req->semester_no))
            ->selectRaw(implode(', ', $selects))
            ->orderBy('a.roll_no')
            ->get();

        return response()->json($data);
    }

    // ══════════════════════════════════════════════════════════════
    // 9. FEE VALUE CHANGE
    // ══════════════════════════════════════════════════════════════
    public function feeValueChangeGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student) return response()->json(['message' => 'Student not found.'], 404);

        $fee = DB::table('fee_receipts')->where('admission_id', $student->admission_id)->latest()->first();

        return response()->json(array_merge((array)$student, ['fee_info' => $fee]));
    }

    public function feeValueChangeStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id'   => 'required|exists:admissions,id',
            'change_type'    => 'required|string', // e.g., Category
            'new_value'      => 'required|string',
            'new_fee_amount' => 'nullable|numeric',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $refNo = $this->refNo('FVC');

        DB::table('amendment_logs')->insert([
            'student_id'   => DB::table('admissions')->find($req->admission_id)?->student_id,
            'admission_id' => $req->admission_id,
            'action_type'  => 'FeeValueChange',
            'changed_data' => json_encode($req->only(['change_type','new_value','new_fee_amount'])),
            'modified_by'  => $req->modified_by ?? 'staff',
            'ref_no'       => $refNo,
            'status'       => 'Pending',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => 'Fee change request queued.', 'ref_no' => $refNo]);
    }

    // ══════════════════════════════════════════════════════════════
    // 10. FEE RESET ON STUDENT PORTAL
    // ══════════════════════════════════════════════════════════════
    public function feeResetGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student) return response()->json(['message' => 'Student not found.'], 404);

        $fees = DB::table('fee_receipts')->where('admission_id', $student->admission_id)->get();

        return response()->json(array_merge((array)$student, ['fees' => $fees]));
    }

    public function feeResetStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id' => 'required|exists:admissions,id',
            'fee_type'     => 'required|in:Registration Fee,Admission Fee,Practical Fee',
            'new_amount'   => 'required|numeric|min:0',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $refNo = $this->refNo('FR');

        DB::table('amendment_logs')->insert([
            'student_id'   => DB::table('admissions')->find($req->admission_id)?->student_id,
            'admission_id' => $req->admission_id,
            'action_type'  => 'FeeReset',
            'changed_data' => json_encode($req->only(['fee_type','new_amount'])),
            'modified_by'  => $req->modified_by ?? 'staff',
            'ref_no'       => $refNo,
            'status'       => 'Pending',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => 'Fee reset request queued.', 'ref_no' => $refNo]);
    }

    // ══════════════════════════════════════════════════════════════
    // 11. BLOCK / UNBLOCK USER
    // ══════════════════════════════════════════════════════════════
    public function blockUnblockGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student) return response()->json(['message' => 'Student not found.'], 404);

        return response()->json($student);
    }

    public function blockUnblockStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'student_id' => 'required|exists:students,id',
            'action'     => 'required|in:Block,Unblock',
            'reason'     => 'required_if:action,Block|nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('students')->where('id', $req->student_id)
            ->update(['is_blocked' => $req->action === 'Block', 'updated_at' => now()]);

        DB::table('amendment_logs')->insert([
            'student_id'  => $req->student_id,
            'action_type' => 'BlockUnblock',
            'changed_data'=> json_encode(['action' => $req->action, 'reason' => $req->reason]),
            'modified_by' => $req->modified_by ?? 'staff',
            'ref_no'      => $this->refNo('BLK'),
            'status'      => 'Completed',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json(['message' => "User {$req->action}ed successfully."]);
    }

    // ══════════════════════════════════════════════════════════════
    // 12. RESTRICTION OF STUDENT
    // ══════════════════════════════════════════════════════════════
    public function restrictionIndex(Request $req)
    {
        return response()->json(
            DB::table('student_restrictions as sr')
                ->join('students as s', 's.id', 'sr.student_id')
                ->leftJoin('admissions as a', 'a.student_id', 's.id')
                ->leftJoin('programs as p', 'p.id', 'a.program_id')
                ->select('sr.*', 's.name', 's.father_name', 's.mobile', 'p.short_name as class')
                ->orderByDesc('sr.created_at')
                ->get()
        );
    }

    public function restrictionStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'student_id'     => 'required|exists:students,id',
            'reason'         => 'required|string',
            'other_reason'   => 'nullable|string|min:20',
            'restriction_by' => 'required|string',
            'authority_name' => 'required|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('student_restrictions')->insert([
            'student_id'     => $req->student_id,
            'reason'         => $req->reason,
            'other_reason'   => $req->other_reason,
            'restriction_by' => $req->restriction_by,
            'authority_name' => $req->authority_name,
            'submitted_by'   => $req->submitted_by ?? 'staff',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('students')->where('id', $req->student_id)
            ->update(['is_restricted' => true, 'updated_at' => now()]);

        return response()->json(['message' => 'Student restricted.']);
    }

    public function restrictionRemove($studentId)
    {
        DB::table('student_restrictions')->where('student_id', $studentId)->delete();
        DB::table('students')->where('id', $studentId)
            ->update(['is_restricted' => false, 'updated_at' => now()]);

        return response()->json(['message' => 'Restriction removed.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 13. ADMISSION CANCEL
    // ══════════════════════════════════════════════════════════════
    public function admissionCancelGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student) return response()->json(['message' => 'Student not found.'], 404);

        $fee = DB::table('fee_receipts')->where('admission_id', $student->admission_id)->latest()->first();

        return response()->json(array_merge((array)$student, ['fee_info' => $fee]));
    }

    public function admissionCancelStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id'    => 'required|exists:admissions,id',
            'cancel_reason'   => 'required|string',
            'cancel_charge'   => 'nullable|numeric',
            'cancel_date'     => 'required|date',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('admissions')->where('id', $req->admission_id)
            ->update(['status' => 'Cancelled', 'updated_at' => now()]);

        $refNo = $this->refNo('CAN');

        DB::table('amendment_logs')->insert([
            'student_id'   => DB::table('admissions')->find($req->admission_id)?->student_id,
            'admission_id' => $req->admission_id,
            'action_type'  => 'AdmissionCancel',
            'changed_data' => json_encode($req->only(['cancel_reason','cancel_charge','cancel_date'])),
            'modified_by'  => $req->modified_by ?? 'staff',
            'ref_no'       => $refNo,
            'status'       => 'Completed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['message' => 'Admission cancelled.', 'ref_no' => $refNo]);
    }

    // ══════════════════════════════════════════════════════════════
    // 14. HOLD OR CANCEL — BY COLLEGE
    // ══════════════════════════════════════════════════════════════
    public function holdCancelIndex(Request $req)
    {
        $q = DB::table('applications as ap')
            ->join('students as s', 's.id', 'ap.student_id')
            ->leftJoin('programs as p', 'p.id', 'ap.program_id')
            ->whereIn('ap.status', ['Hold', 'Cancelled'])
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('ap.application_no', 'ilike', "%{$req->search}%")
                   ->orWhere('ap.reg_no',        'ilike', "%{$req->search}%");
            }))
            ->select('ap.*', 's.name', 's.father_name', 's.mobile', 'p.short_name as class')
            ->orderByDesc('ap.updated_at');

        return response()->json($q->paginate(50));
    }

    public function holdCancelStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'application_id' => 'required|exists:applications,id',
            'action'         => 'required|in:Hold,Cancel',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        DB::table('applications')->where('id', $req->application_id)
            ->update(['status' => $req->action === 'Cancel' ? 'Cancelled' : 'Hold', 'updated_at' => now()]);

        return response()->json(['message' => "Application {$req->action}ed."]);
    }

    // ══════════════════════════════════════════════════════════════
    // 15. AMENDMENT LOG (for approval workflows)
    // ══════════════════════════════════════════════════════════════
    public function logIndex(Request $req)
    {
        return response()->json(
            DB::table('amendment_logs as al')
                ->join('students as s', 's.id', 'al.student_id')
                ->when($req->action_type, fn($q) => $q->where('al.action_type', $req->action_type))
                ->when($req->status,      fn($q) => $q->where('al.status', $req->status))
                ->select('al.*', 's.name', 's.father_name', 's.mobile')
                ->orderByDesc('al.created_at')
                ->paginate(50)
        );
    }

    public function logApprove(Request $req, $id)
    {
        DB::table('amendment_logs')->where('id', $id)
            ->update([
                'status'      => 'Approved',
                'approved_by' => $req->approved_by ?? 'authority',
                'approved_at' => now(),
                'updated_at'  => now(),
            ]);

        return response()->json(['message' => 'Amendment approved.']);
    }
}
