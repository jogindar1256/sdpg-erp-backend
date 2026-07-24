<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AmendmentController extends Controller
{
    // ══════════════════════════════════════════════════════════════
    // SHARED HELPER — lookup student by any identifier
    // ══════════════════════════════════════════════════════════════
    /**
     * `students` has no name/father_name/mother_name/dob/spouse_name/address
     * columns (only first_name/middle_name/last_name, date_of_birth,
     * permanent_*) — name/father_name/mother_name/dob come from the latest
     * direct_registrations snapshot instead, same pattern used everywhere
     * else (ApplicationController::parseApp(), studentRegistrationDashboard()).
     * spouse_name and a free-text "address" don't exist in either table.
     *
     * The `applications` table this used to left-join for application_no/
     * reg_no has been dropped — nothing ever inserted a row into it, so
     * those columns were always null anyway. student_applications is the
     * real, live application table; its application_no is joined in instead.
     */
    private function regJoinSub()
    {
        return DB::table('direct_registrations')
            ->select('user_id', DB::raw('MAX(id) as reg_id'))
            ->whereNull('deleted_at')
            ->groupBy('user_id');
    }

    private function findStudent(string $key): ?object
    {
        $latestReg = $this->regJoinSub();
        $latestApp = DB::table('student_applications')
            ->select('student_id', DB::raw('MAX(id) as app_id'))
            ->whereNull('deleted_at')
            ->groupBy('student_id');

        $row = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoinSub($latestApp, 'la', 'la.student_id', 's.id')
            ->leftJoin('student_applications as sa', 'sa.id', 'la.app_id')
            ->where(function ($q) use ($key) {
                $q->where('a.roll_no', $key)
                    ->orWhere('a.enrollment_no', $key)
                    ->orWhere('a.account_no', $key)
                    ->orWhere('s.mobile', $key)
                    ->orWhere('s.aadhar_no', $key)
                    ->orWhere('sa.application_no', $key)
                    ->orWhere('dr.registration_no', $key)
                    ->orWhere('s.id', $key);
            })
            ->select(
                'a.id as admission_id',
                'a.roll_no',
                'a.enrollment_no',
                'a.account_no',
                'a.semester_no',
                'a.status as admission_status',
                's.id as student_id',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name', 'dr.father_name', 'dr.mother_name', 'dr.dob',
                's.mobile',
                's.gender',
                's.category',
                's.aadhar_no',
                's.abc_id',
                's.ddurn',
                's.permanent_address as address',
                'p.short_name as class',
                'p.full_name',
                'p.level',
                'sa.application_no',
                'dr.registration_no as reg_no',
                'sa.status as app_status'
            )
            ->first();

        if ($row && empty($row->name)) {
            $row->name = trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name])));
        }

        return $row;
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
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $k = $req->query;
        $latestReg = $this->regJoinSub();
        $latestApp = DB::table('student_applications')
            ->select('student_id', DB::raw('MAX(id) as app_id'))
            ->whereNull('deleted_at')
            ->groupBy('student_id');

        $rows = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoinSub($latestApp, 'la', 'la.student_id', 's.id')
            ->leftJoin('student_applications as sa', 'sa.id', 'la.app_id')
            ->leftJoin('semester_registrations as sr', function ($j) use ($req) {
                $j->on('sr.admission_id', 'a.id')
                    ->where('sr.session_year', $req->session_year ?? date('Y') . '-' . (date('Y') + 1));
            })
            ->leftJoin('fee_receipts as fr', 'fr.admission_id', 'a.id')
            ->where(function ($q) use ($k) {
                $q->where('a.roll_no', 'ilike', "%{$k}%")
                    ->orWhere('dr.name', 'ilike', "%{$k}%")
                    ->orWhere('dr.father_name', 'ilike', "%{$k}%")
                    ->orWhere('dr.mother_name', 'ilike', "%{$k}%")
                    ->orWhere('s.first_name', 'ilike', "%{$k}%")
                    ->orWhere('s.last_name', 'ilike', "%{$k}%")
                    ->orWhere('s.mobile', 'ilike', "%{$k}%")
                    ->orWhere('s.aadhar_no', 'ilike', "%{$k}%")
                    ->orWhere('a.enrollment_no', 'ilike', "%{$k}%")
                    ->orWhere('sa.application_no', 'ilike', "%{$k}%")
                    ->orWhere('dr.registration_no', 'ilike', "%{$k}%");
            })
            ->select(
                'a.id as admission_id',
                'a.roll_no',
                'a.enrollment_no',
                'a.account_no',
                'a.semester_no',
                'a.status as admission_status',
                's.id as student_id',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name', 'dr.father_name', 'dr.mother_name',
                's.mobile',
                's.gender',
                's.category',
                's.aadhar_no',
                's.abc_id',
                's.ddurn',
                'p.short_name as class',
                'p.level',
                'sa.application_no',
                'dr.registration_no as reg_no',
                'sa.status as app_status',
                'sr.status as reg_status',
                DB::raw('MAX(fr.receipt_no) as final_receipt_no'),
                DB::raw('MAX(fr.status) as fee_status')
            )
            ->groupBy(
                'a.id',
                'a.roll_no',
                'a.enrollment_no',
                'a.account_no',
                'a.semester_no',
                'a.status',
                's.id',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name', 'dr.father_name', 'dr.mother_name',
                's.mobile',
                's.gender',
                's.category',
                's.aadhar_no',
                's.abc_id',
                's.ddurn',
                'p.short_name',
                'p.level',
                'sa.application_no',
                'dr.registration_no',
                'sa.status',
                'sr.status'
            )
            ->limit(20)
            ->get();

        $rows->transform(function ($row) {
            if (empty($row->name)) {
                $row->name = trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name])));
            }
            return $row;
        });

        return response()->json(['count' => count($rows), 'data' => $rows]);
    }

    // ══════════════════════════════════════════════════════════════
    // 2. MODIFY STUDENT DATA
    // ══════════════════════════════════════════════════════════════
    public function modifyGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student)
            return response()->json(['message' => 'Student not found.'], 404);

        // Return full student profile + the registration snapshot (name/
        // father_name/mother_name/dob/caste_cert_* live there, not on students).
        $full = DB::table('students')->find($student->student_id);
        $reg  = $full ? DB::table('direct_registrations')
            ->where('user_id', $full->user_id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first() : null;

        return response()->json(array_merge((array) $student, [
            'full_profile' => $full,
            'registration' => $reg,
        ]));
    }

    /**
     * `students` and `direct_registrations` split the identity data across
     * two tables (see findStudent()'s doc comment). The previous $allowed
     * list here targeted a single flat set of columns that never matched
     * either table — name/father_name/mother_name/dob/caste_cert_* belong
     * on direct_registrations, several others (district/state/address) were
     * just the wrong column names for students' real permanent_* columns,
     * and a few (name_hindi, marital_status, spouse_name*, police_station,
     * post, sub_district, blood_group) have no destination column anywhere
     * in the current schema and are dropped rather than silently failing.
     */
    public function modifyUpdate(Request $req)
    {
        $v = Validator::make($req->all(), [
            'student_id' => 'required|exists:students,id',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        // → students columns
        $studentFieldMap = [
            'religion'            => 'religion',
            'nationality'         => 'nationality',
            'bank_name'           => 'bank_name',
            'abc_id'              => 'abc_id',
            'enrollment_no'       => 'enrollment_no',
            'university_roll_no'  => 'university_roll_no',
            'aadhar_no'           => 'aadhar_no',
            'ddurn'               => 'ddurn',
            'family_id'           => 'family_id',
            'district'            => 'permanent_district',
            'state'               => 'permanent_state',
            'address'             => 'permanent_address',
        ];
        // → direct_registrations columns
        $regFieldMap = [
            'name'            => 'name',
            'father_name'     => 'father_name',
            'mother_name'     => 'mother_name',
            'dob'             => 'dob',
            'caste_cert_no'   => 'caste_cert_no',
            'caste_cert_date' => 'caste_cert_date',
        ];

        $in = $req->all();
        $studentData = [];
        foreach ($studentFieldMap as $in_key => $col) {
            if (array_key_exists($in_key, $in) && $in[$in_key] !== null) $studentData[$col] = $in[$in_key];
        }
        $regData = [];
        foreach ($regFieldMap as $in_key => $col) {
            if (array_key_exists($in_key, $in) && $in[$in_key] !== null) $regData[$col] = $in[$in_key];
        }

        if (empty($studentData) && empty($regData)) {
            return response()->json(['message' => 'No editable fields were provided.'], 422);
        }

        if (!empty($studentData)) {
            $studentData['updated_at'] = now();
            DB::table('students')->where('id', $req->student_id)->update($studentData);
        }

        if (!empty($regData)) {
            $student = DB::table('students')->find($req->student_id);
            $reg = $student ? DB::table('direct_registrations')
                ->where('user_id', $student->user_id)
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->first() : null;

            if ($reg) {
                $regData['updated_at'] = now();
                DB::table('direct_registrations')->where('id', $reg->id)->update($regData);
            }
        }

        $data = array_merge($studentData, $regData);

        // Log amendment
        DB::table('amendment_logs')->insert([
            'student_id' => $req->student_id,
            'action_type' => 'ModifyData',
            'changed_data' => json_encode($data),
            'modified_by' => $req->modified_by ?? 'staff',
            'ref_no' => $this->refNo('MD'),
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Data updated and sent for approval.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 3. SUBJECT CHANGE
    // ══════════════════════════════════════════════════════════════
    public function subjectChangeGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student)
            return response()->json(['message' => 'Student not found.'], 404);

        // Current subjects — application_subjects (the dropped dead table)
        // never held real data. Subject selections live in
        // student_applications.selected_subjects (JSON array of subject
        // ids, or objects with a subject_id/id key).
        $app = DB::table('student_applications')
            ->where('student_id', $student->student_id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $subjectIds = [];
        if ($app && $app->selected_subjects) {
            $raw = json_decode($app->selected_subjects, true) ?? [];
            foreach ($raw as $item) {
                $subjectIds[] = is_array($item) ? ($item['subject_id'] ?? $item['id'] ?? null) : $item;
            }
            $subjectIds = array_values(array_filter($subjectIds));
        }

        $subjects = $subjectIds
            ? DB::table('subjects')->whereIn('id', $subjectIds)->get()
            : collect();

        return response()->json(array_merge((array) $student, ['current_subjects' => $subjects]));
    }

    public function subjectChangeStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id' => 'required|exists:admissions,id',
            'new_subjects' => 'required|array',
            'drop_subject_id' => 'nullable|exists:subjects,id',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $refNo = $this->refNo('SUB');

        DB::table('amendment_logs')->insert([
            'student_id' => DB::table('admissions')->find($req->admission_id)?->student_id,
            'admission_id' => $req->admission_id,
            'action_type' => 'SubjectChange',
            'changed_data' => json_encode([
                'new_subjects' => $req->new_subjects,
                'drop_subject_id' => $req->drop_subject_id,
            ]),
            'modified_by' => $req->modified_by ?? 'staff',
            'ref_no' => $refNo,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Subject change request queued.', 'ref_no' => $refNo]);
    }

    // ══════════════════════════════════════════════════════════════
    // 4. UPDATE MOBILE NO
    // ══════════════════════════════════════════════════════════════
    public function updateMobileGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student)
            return response()->json(['message' => 'Student not found.'], 404);

        return response()->json($student);
    }

    public function updateMobileStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'student_id' => 'required|exists:students,id',
            'new_mobile' => 'required|digits:10',
            'without_otp' => 'boolean',
            'otp' => 'required_if:without_otp,false|nullable|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('students')->where('id', $req->student_id)
            ->update(['mobile' => $req->new_mobile, 'updated_at' => now()]);

        DB::table('amendment_logs')->insert([
            'student_id' => $req->student_id,
            'action_type' => 'MobileUpdate',
            'changed_data' => json_encode(['new_mobile' => $req->new_mobile, 'without_otp' => $req->without_otp]),
            'modified_by' => $req->modified_by ?? 'staff',
            'ref_no' => $this->refNo('MOB'),
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Mobile number updated. Sent for approval.']);
    }

    public function sendOtp(Request $req)
    {
        $v = Validator::make($req->all(), ['mobile' => 'required|digits:10']);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        // In production: integrate with SMS gateway
        $otp = rand(100000, 999999);

        return response()->json(['message' => 'OTP sent.', 'debug_otp' => $otp]); // remove debug_otp in prod
    }

    // ══════════════════════════════════════════════════════════════
    // 5. UPDATE TC & MIGRATION
    // ══════════════════════════════════════════════════════════════
    public function updateTcGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student)
            return response()->json(['message' => 'Student not found.'], 404);

        $app = DB::table('student_applications')
            ->where('student_id', $student->student_id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $part4 = ($app && $app->part_4) ? json_decode($app->part_4, true) : null;

        $tcKeys = ['tc_condition', 'tc_org_name', 'tc_address', 'tc_contact', 'tc_serial_no', 'tc_ledger_no', 'tc_issue_date', 'tc_behavior', 'tc_statement_signed'];
        $migKeys = ['has_migration', 'mig_condition', 'mig_university', 'mig_address', 'mig_state', 'mig_district', 'mig_pin', 'mig_last_institute', 'mig_inst_address', 'mig_inst_state', 'mig_inst_district', 'mig_inst_pin', 'mig_leave_year', 'mig_reason', 'mig_statement_signed'];

        return response()->json(array_merge((array) $student, [
            'application_id' => $app->id ?? null,
            'tc' => $part4 ? array_intersect_key($part4, array_flip($tcKeys)) : null,
            'migration' => $part4 ? array_intersect_key($part4, array_flip($migKeys)) : null,
        ]));
    }

    public function updateTcStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id' => 'required|exists:admissions,id',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $studentId = DB::table('admissions')->find($req->admission_id)?->student_id;

        $app = DB::table('student_applications')
            ->where('student_id', $studentId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        if (!$app) {
            return response()->json(['message' => 'No application record found for this student to attach TC/Migration details to.'], 404);
        }

        $part4 = $app->part_4 ? json_decode($app->part_4, true) : [];
        if ($req->tc_data)
            $part4 = array_merge($part4, $req->tc_data);
        if ($req->migration_data)
            $part4 = array_merge($part4, $req->migration_data);

        DB::table('student_applications')->where('id', $app->id)->update([
            'part_4' => json_encode($part4),
            'updated_at' => now(),
        ]);

        DB::table('amendment_logs')->insert([
            'student_id' => $studentId,
            'action_type' => 'TCMigrationUpdate',
            'modified_by' => $req->modified_by ?? 'staff',
            'ref_no' => $this->refNo('TC'),
            'status' => 'Completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'TC/Migration updated.']);
    }

    // ══════════════════════════════════════════════════════════════
    // 6. UPDATE PAPER FOR STUDENT
    // ══════════════════════════════════════════════════════════════
    public function updatePaperIndex(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'required|string',
            'paper_type' => 'nullable|string',
            'subject_id' => 'nullable|exists:subjects,id',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        // Get all papers for program+semester
        $papers = DB::table('subject_papers as sp')
            ->join('subjects as sub', 'sub.id', 'sp.subject_id')
            ->when($req->subject_id, fn($q) => $q->where('sp.subject_id', $req->subject_id))
            ->where('sp.semester_no', $req->semester_no)
            ->select('sp.*', 'sub.name as subject_name')
            ->get();

        // Students in this program/semester
        $latestReg = $this->regJoinSub();
        $students = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->where('a.program_id', $req->program_id)
            ->where('a.semester_no', $req->semester_no)
            ->select(
                'a.id as admission_id', 'a.roll_no', 'a.enrollment_no',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name', 'dr.father_name'
            )
            ->orderBy('a.roll_no')
            ->get();

        $students->transform(function ($row) {
            if (empty($row->name)) {
                $row->name = trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name])));
            }
            return $row;
        });

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
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        return response()->json([
            'message' => 'Paper amendment is not available yet. It previously updated an unused table '
                . '(application_subjects) that never held real data and has been removed. This needs to be '
                . 'rebuilt against student_applications.selected_subjects before it can be used.',
        ], 501);
    }

    // ══════════════════════════════════════════════════════════════
    // 7. DOWNLOAD DOCUMENTS
    // ══════════════════════════════════════════════════════════════
    public function downloadDocuments(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_type' => 'nullable|in:Odd,Even,All',
            'semester_no' => 'nullable|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $latestReg = $this->regJoinSub();
        $latestApp = DB::table('student_applications')
            ->select('student_id', DB::raw('MAX(id) as app_id'))
            ->where('application_type', 'fresh')
            ->whereNull('deleted_at')
            ->groupBy('student_id');

        $students = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoinSub($latestApp, 'la', 'la.student_id', 's.id')
            ->leftJoin('student_applications as sa', 'sa.id', 'la.app_id')
            ->where('a.program_id', $req->program_id)
            ->when(
                $req->semester_no && $req->semester_no !== 'All',
                fn($q) => $q->where('a.semester_no', $req->semester_no)
            )
            ->select(
                'a.id as admission_id',
                'a.roll_no',
                'a.account_no',
                'a.semester_no',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name',
                's.mobile',
                'sa.application_no'
            )
            ->orderBy('a.roll_no')
            ->get();

        $students->transform(function ($row) {
            if (empty($row->name)) {
                $row->name = trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name])));
            }
            return $row;
        });

        return response()->json($students);
    }

    // ══════════════════════════════════════════════════════════════
    // 8. IMPORT / EXPORT DATA
    // ══════════════════════════════════════════════════════════════
    public function importData(Request $req)
    {
        $v = Validator::make($req->all(), [
            'session_year' => 'nullable|string',
            'program_id' => 'nullable|exists:programs,id',
            'semester_no' => 'nullable|string',
            'table_type' => 'required|in:Registration,Admission,Examination',
            'fields' => 'required|array|min:1',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        // Build dynamic query based on selected fields. `students` has no
        // name/father_name/mother_name/dob columns — those live on
        // direct_registrations (dr); `applications` (ap.application_no) was
        // dropped (never populated by anything) — student_applications (sa)
        // is the real, live application table.
        $fieldMap = [
            'Registration No' => 'a.roll_no',
            'Application No' => 'sa.application_no',
            'University Roll No' => 'a.enrollment_no',
            'Enrolment No' => 'a.account_no',
            'Student Name English' => 'dr.name',
            'Father Name English' => 'dr.father_name',
            'Mother Name English' => 'dr.mother_name',
            'Date of Birth' => 'dr.dob',
            'Category' => 's.category',
            'Gander' => 's.gender',
        ];

        $selects = [];
        foreach ($req->fields as $field) {
            if (isset($fieldMap[$field]))
                $selects[] = "{$fieldMap[$field]} as \"{$field}\"";
        }
        if (empty($selects))
            $selects = ['dr.name as "Student Name English"'];

        $latestReg = $this->regJoinSub();
        $latestApp = DB::table('student_applications')
            ->select('student_id', DB::raw('MAX(id) as app_id'))
            ->where('application_type', 'fresh')
            ->whereNull('deleted_at')
            ->groupBy('student_id');

        $data = DB::table('admissions as a')
            ->join('students as s', 's.id', 'a.student_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->leftJoinSub($latestApp, 'la', 'la.student_id', 's.id')
            ->leftJoin('student_applications as sa', 'sa.id', 'la.app_id')
            ->when($req->program_id, fn($q) => $q->where('a.program_id', $req->program_id))
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
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student)
            return response()->json(['message' => 'Student not found.'], 404);

        $fee = DB::table('fee_receipts')->where('admission_id', $student->admission_id)->latest()->first();

        return response()->json(array_merge((array) $student, ['fee_info' => $fee]));
    }

    public function feeValueChangeStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id' => 'required|exists:admissions,id',
            'change_type' => 'required|string', // e.g., Category
            'new_value' => 'required|string',
            'new_fee_amount' => 'nullable|numeric',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $refNo = $this->refNo('FVC');

        DB::table('amendment_logs')->insert([
            'student_id' => DB::table('admissions')->find($req->admission_id)?->student_id,
            'admission_id' => $req->admission_id,
            'action_type' => 'FeeValueChange',
            'changed_data' => json_encode($req->only(['change_type', 'new_value', 'new_fee_amount'])),
            'modified_by' => $req->modified_by ?? 'staff',
            'ref_no' => $refNo,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Fee change request queued.', 'ref_no' => $refNo]);
    }

    // ══════════════════════════════════════════════════════════════
    // 10. FEE RESET ON STUDENT PORTAL
    // ══════════════════════════════════════════════════════════════
    public function feeResetGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student)
            return response()->json(['message' => 'Student not found.'], 404);

        $fees = DB::table('fee_receipts')->where('admission_id', $student->admission_id)->get();

        return response()->json(array_merge((array) $student, ['fees' => $fees]));
    }

    public function feeResetStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id' => 'required|exists:admissions,id',
            'fee_type' => 'required|in:Registration Fee,Admission Fee,Practical Fee',
            'new_amount' => 'required|numeric|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $refNo = $this->refNo('FR');

        DB::table('amendment_logs')->insert([
            'student_id' => DB::table('admissions')->find($req->admission_id)?->student_id,
            'admission_id' => $req->admission_id,
            'action_type' => 'FeeReset',
            'changed_data' => json_encode($req->only(['fee_type', 'new_amount'])),
            'modified_by' => $req->modified_by ?? 'staff',
            'ref_no' => $refNo,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Fee reset request queued.', 'ref_no' => $refNo]);
    }

    // ══════════════════════════════════════════════════════════════
    // 11. BLOCK / UNBLOCK USER
    // ══════════════════════════════════════════════════════════════
    public function blockUnblockGet(Request $req)
    {
        $v = Validator::make($req->all(), ['search' => 'required|string']);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student)
            return response()->json(['message' => 'Student not found.'], 404);

        return response()->json($student);
    }

    public function blockUnblockStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'student_id' => 'required|exists:students,id',
            'action' => 'required|in:Block,Unblock',
            'reason' => 'required_if:action,Block|nullable|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('students')->where('id', $req->student_id)
            ->update(['is_blocked' => $req->action === 'Block', 'updated_at' => now()]);

        DB::table('amendment_logs')->insert([
            'student_id' => $req->student_id,
            'action_type' => 'BlockUnblock',
            'changed_data' => json_encode(['action' => $req->action, 'reason' => $req->reason]),
            'modified_by' => $req->modified_by ?? 'staff',
            'ref_no' => $this->refNo('BLK'),
            'status' => 'Completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "User {$req->action}ed successfully."]);
    }

    // ══════════════════════════════════════════════════════════════
    // 12. RESTRICTION OF STUDENT
    // ══════════════════════════════════════════════════════════════
    public function restrictionIndex(Request $req)
    {
        $latestReg = $this->regJoinSub();
        $rows = DB::table('student_restrictions as sr')
            ->join('students as s', 's.id', 'sr.student_id')
            ->leftJoin('admissions as a', 'a.student_id', 's.id')
            ->leftJoin('programs as p', 'p.id', 'a.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->select(
                'sr.*',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name', 'dr.father_name',
                's.mobile', 'p.short_name as class'
            )
            ->orderByDesc('sr.created_at')
            ->get();

        $rows->transform(function ($row) {
            if (empty($row->name)) {
                $row->name = trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name])));
            }
            return $row;
        });

        return response()->json($rows);
    }

    public function restrictionStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'student_id' => 'required|exists:students,id',
            'reason' => 'required|string',
            'other_reason' => 'nullable|string|min:20',
            'restriction_by' => 'required|string',
            'authority_name' => 'required|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('student_restrictions')->insert([
            'student_id' => $req->student_id,
            'reason' => $req->reason,
            'other_reason' => $req->other_reason,
            'restriction_by' => $req->restriction_by,
            'authority_name' => $req->authority_name,
            'submitted_by' => $req->submitted_by ?? 'staff',
            'created_at' => now(),
            'updated_at' => now(),
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
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $student = $this->findStudent($req->search);
        if (!$student)
            return response()->json(['message' => 'Student not found.'], 404);

        $fee = DB::table('fee_receipts')->where('admission_id', $student->admission_id)->latest()->first();

        return response()->json(array_merge((array) $student, ['fee_info' => $fee]));
    }

    public function admissionCancelStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'admission_id' => 'required|exists:admissions,id',
            'cancel_reason' => 'required|string',
            'cancel_charge' => 'nullable|numeric',
            'cancel_date' => 'required|date',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('admissions')->where('id', $req->admission_id)
            ->update(['status' => 'Cancelled', 'updated_at' => now()]);

        $refNo = $this->refNo('CAN');

        DB::table('amendment_logs')->insert([
            'student_id' => DB::table('admissions')->find($req->admission_id)?->student_id,
            'admission_id' => $req->admission_id,
            'action_type' => 'AdmissionCancel',
            'changed_data' => json_encode($req->only(['cancel_reason', 'cancel_charge', 'cancel_date'])),
            'modified_by' => $req->modified_by ?? 'staff',
            'ref_no' => $refNo,
            'status' => 'Completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Admission cancelled.', 'ref_no' => $refNo]);
    }

    // ══════════════════════════════════════════════════════════════
    // 14. HOLD OR CANCEL — BY COLLEGE
    // ══════════════════════════════════════════════════════════════
    /**
     * Rewired to student_applications — the `applications` table this used
     * to query (as its FROM table, no less) has been dropped; it was never
     * populated by anything so this always returned an empty page anyway.
     * Status values match student_applications' real enum, same as
     * holdCancelStore() below.
     */
    public function holdCancelIndex(Request $req)
    {
        $latestReg = $this->regJoinSub();
        $q = DB::table('student_applications as ap')
            ->join('students as s', 's.id', 'ap.student_id')
            ->leftJoin('programs as p', 'p.id', 'ap.program_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->whereNull('ap.deleted_at')
            ->whereIn('ap.status', ['on_hold', 'cancelled'])
            ->when($req->search, fn($q) => $q->where(function ($q2) use ($req) {
                $q2->where('ap.application_no', 'ilike', "%{$req->search}%")
                    ->orWhere('dr.registration_no', 'ilike', "%{$req->search}%");
            }))
            ->select(
                'ap.*',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name', 'dr.father_name',
                's.mobile', 'p.short_name as class'
            )
            ->orderByDesc('ap.updated_at');

        $result = $q->paginate(50);
        $result->getCollection()->transform(function ($row) {
            if (empty($row->name)) {
                $row->name = trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name])));
            }
            return $row;
        });

        return response()->json($result);
    }

    public function holdCancelStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'application_id' => 'required|exists:student_applications,id',
            'action' => 'required|in:Hold,Cancel',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('student_applications')->where('id', $req->application_id)
            ->update([
                'status' => $req->action === 'Cancel' ? 'cancelled' : 'on_hold',
                'updated_at' => now(),
            ]);

        return response()->json(['message' => "Application {$req->action}ed."]);
    }

    // ══════════════════════════════════════════════════════════════
    // 15. AMENDMENT LOG (for approval workflows)
    // ══════════════════════════════════════════════════════════════
    public function logIndex(Request $req)
    {
        $latestReg = $this->regJoinSub();
        $result = DB::table('amendment_logs as al')
            ->join('students as s', 's.id', 'al.student_id')
            ->leftJoinSub($latestReg, 'lr', 'lr.user_id', 's.user_id')
            ->leftJoin('direct_registrations as dr', 'dr.id', 'lr.reg_id')
            ->when($req->action_type, fn($q) => $q->where('al.action_type', $req->action_type))
            ->when($req->status, fn($q) => $q->where('al.status', $req->status))
            ->select(
                'al.*',
                's.first_name', 's.middle_name', 's.last_name',
                'dr.name', 'dr.father_name',
                's.mobile'
            )
            ->orderByDesc('al.created_at')
            ->paginate(50);

        $result->getCollection()->transform(function ($row) {
            if (empty($row->name)) {
                $row->name = trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name])));
            }
            return $row;
        });

        return response()->json($result);
    }

    public function logApprove(Request $req, $id)
    {
        DB::table('amendment_logs')->where('id', $id)
            ->update([
                'status' => 'Approved',
                'approved_by' => $req->approved_by ?? 'authority',
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Amendment approved.']);
    }
}