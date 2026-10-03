<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use App\Models\Program;
use App\Models\Subject;
use App\Models\FeeHead;
use App\Models\FeeStructure;
use App\Models\SemesterMaster;

class MasterSettingsController extends Controller
{
    // ══════════════════════════════════════════════════════════════════
    // ADMISSION SETTINGS
    // ══════════════════════════════════════════════════════════════════

    // 1. Application Schedule ─────────────────────────────────────────
    public function applicationScheduleIndex(Request $req)
    {
        $data = DB::table('application_schedules as s')
            ->join('programs as p', 'p.id', 's.program_id')
            ->select('s.*', 'p.short_name as course', 'p.full_name')
            ->when($req->session_year, fn($q) => $q->where('s.session_year', $req->session_year))
            ->orderBy('s.created_at', 'desc')
            ->paginate(20);
        return response()->json($data);
    }

    public function applicationScheduleStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'session_year' => 'required|string',
            // semester_name dropped — parity is derived from semester_no.
            'semester_no' => 'required|integer|min:1',
            'exam_mode' => 'required|in:Regular,Back Paper,Upgrade',
            // Widened from a bare date to a full timestamp so the office can
            // set an exact admission open/close TIME, not just a day.
            'start_admission' => 'required|date',
            'close_admission' => 'required|date|after:start_admission',
            'late_fee_applicable' => 'required|boolean',
            'late_fee' => 'nullable|numeric|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $rec = DB::table('application_schedules')->insertGetId(array_merge($req->only([
            'program_id',
            'session_year',
            'semester_no',
            'exam_mode',
            'start_admission',
            'close_admission',
            'late_fee_applicable',
            'late_fee',
        ]), ['created_at' => now(), 'updated_at' => now()]));

        return response()->json(['id' => $rec, 'message' => 'Schedule saved.'], 201);
    }

    public function applicationScheduleUpdate(Request $req, $id)
    {
        // Update previously had no validation at all, so a bad edit (close
        // time before start time, missing program) would save silently.
        // Now enforced the same as create.
        $v = Validator::make($req->all(), [
            'program_id' => 'sometimes|required|exists:programs,id',
            'session_year' => 'sometimes|required|string',
            'semester_no' => 'sometimes|required|integer|min:1',
            'exam_mode' => 'sometimes|required|in:Regular,Back Paper,Upgrade',
            'start_admission' => 'sometimes|required|date',
            'close_admission' => 'sometimes|required|date|after:start_admission',
            'late_fee_applicable' => 'sometimes|required|boolean',
            'late_fee' => 'nullable|numeric|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('application_schedules')->where('id', $id)->update(array_merge(
            $req->only([
                'program_id',
                'session_year',
                'semester_no',
                'exam_mode',
                'start_admission',
                'close_admission',
                'late_fee_applicable',
                'late_fee'
            ]),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function applicationScheduleDestroy($id)
    {
        DB::table('application_schedules')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 2. Admission Condition ──────────────────────────────────────────
    public function admissionConditionIndex(Request $req)
    {
        // Previously selected admission_conditions.* only, with no join to
        // programs — so the "Class" column the frontend tries to render
        // was always empty even though program_id was saved correctly.
        $rows = DB::table('admission_conditions as ac')
            ->join('programs as p', 'p.id', 'ac.program_id')
            ->select('ac.*', 'p.short_name as course', 'p.full_name', 'p.name as program_name')
            ->when($req->program_id, fn($q) => $q->where('ac.program_id', $req->program_id))
            ->when($req->session_year, fn($q) => $q->where('ac.session_year', $req->session_year))
            ->orderByDesc('ac.id')
            ->get();

        // category_requirements is stored as a JSON string column — decode it
        // here so the frontend gets a real object per row instead of having
        // to JSON.parse it itself.
        $rows->transform(function ($r) {
            $r->category_requirements = $r->category_requirements ? json_decode($r->category_requirements, true) : null;
            return $r;
        });

        return response()->json($rows);
    }

    public function admissionConditionStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'session_year' => 'required|string',
            'semester_no' => 'required|string',
            'qualifying_class' => 'required|string',
            'condition_type' => 'required|in:Open Admission,Through Counselling,Cut Off Merit List,Out Of Merit List',
            'allotted_seat' => 'required|integer',
            'is_blocked' => 'nullable|boolean',
            // Required-% by category is keyed by category code (gen/obc/sc/st/ews),
            // each holding a Mark-vs-CGPA type plus separate Male/Female/Trans
            // values — replaces the old single-value-per-category columns.
            'category_requirements'              => 'required|array',
            'category_requirements.gen'          => 'required|array',
            'category_requirements.obc'          => 'required|array',
            'category_requirements.sc'           => 'required|array',
            'category_requirements.st'           => 'required|array',
            'category_requirements.ews'          => 'required|array',
            'category_requirements.*.mark_type'  => 'required|in:Mark,CGPA',
            'category_requirements.*.male'       => 'nullable|numeric|min:0',
            'category_requirements.*.female'     => 'nullable|numeric|min:0',
            'category_requirements.*.trans'      => 'nullable|numeric|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('admission_conditions')->updateOrInsert(
            ['program_id' => $req->program_id, 'session_year' => $req->session_year, 'semester_no' => $req->semester_no],
            [
                'qualifying_class'       => $req->qualifying_class,
                'condition_type'         => $req->condition_type,
                'allotted_seat'          => $req->allotted_seat,
                'is_blocked'             => (bool) $req->is_blocked,
                'category_requirements'  => json_encode($req->category_requirements),
                'updated_at'             => now(),
            ]
        );
        return response()->json(['message' => 'Condition saved.']);
    }

    public function admissionConditionDestroy($id)
    {
        $deleted = DB::table('admission_conditions')->where('id', $id)->delete();
        if (!$deleted) {
            return response()->json(['message' => 'Condition not found.'], 404);
        }
        return response()->json(['message' => 'Condition deleted.']);
    }

    // 3. Enclosure Master ─────────────────────────────────────────────

    // GET /settings/admission/enclosure-types — the fixed, org-wide
    // enclosure/document name list (seeded by EnclosureTypeSeeder). Drives
    // both the Enclosure Master grid's rows and the read-only "Enclosure
    // Constants" panel shown below it — same list, two views. UI-only for
    // now (no add/edit/delete route exposed yet); that lands once specific
    // management requirements are defined.
    public function enclosureTypesIndex()
    {
        return response()->json(
            DB::table('enclosure_types')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'key', 'sort_order'])
        );
    }

    public function enclosureMasterIndex(Request $req)
    {
        return response()->json(
            DB::table('enclosure_masters')
                ->when($req->program_id, fn($q) => $q->where('program_id', $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('semester_no', $req->semester_no))
                ->when($req->admission_mode, fn($q) => $q->where('admission_mode', $req->admission_mode))
                ->orderBy('document_name')
                ->get()
        );
    }

    public function enclosureMasterStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'required|string',
            'admission_mode' => 'required|string',
            'enclosure_type_id' => 'required|exists:enclosure_types,id',
            'is_required' => 'required|boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $typeName = DB::table('enclosure_types')->where('id', $req->enclosure_type_id)->value('name');

        $keys = [
            'program_id' => $req->program_id,
            'semester_no' => $req->semester_no,
            'admission_mode' => $req->admission_mode,
            'enclosure_type_id' => $req->enclosure_type_id,
        ];

        // Idempotent: same enclosure type for the same class+semester+mode
        // updates the existing row instead of inserting a duplicate.
        // document_name is kept in sync as a denormalized display copy —
        // enclosure_type_id (not the text) is the real identity now.
        DB::table('enclosure_masters')->updateOrInsert(
            $keys,
            ['document_name' => $typeName, 'is_required' => $req->boolean('is_required'), 'updated_at' => now()]
        );

        return response()->json(['message' => 'Document saved.'], 201);
    }

    public function enclosureMasterDestroy($id)
    {
        DB::table('enclosure_masters')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    public function enclosureMasterBulkStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'required|string',
            'admission_mode' => 'required|string',
            'rows' => 'required|array',
            // Every real document row must carry an enclosure_type_id.
            // "Attach Color Photographs" is the one exception — it's a
            // fixed synthetic row (see enclosure/page.tsx's PHOTO_ROW_ID)
            // that never went into enclosure_types, since Photo is handled
            // as its own always-mandatory upload outside Master Settings.
            // It's identified purely by document_name and always saved
            // with enclosure_type_id = null.
            'rows.*.enclosure_type_id' => 'nullable|exists:enclosure_types,id',
            'rows.*.document_name' => 'nullable|string|max:255',
            'rows.*.condition' => 'nullable|string|max:20',
            'rows.*.enclose' => 'nullable|boolean',
            'rows.*.scan_copy' => 'nullable|boolean',
            'rows.*.photo_count' => 'nullable|string|max:5',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $typeNames = DB::table('enclosure_types')->pluck('name', 'id');

        foreach ($req->rows as $row) {
            $typeId = $row['enclosure_type_id'] ?? null;

            if (!$typeId && ($row['document_name'] ?? null) !== 'Attach Color Photographs') {
                // Not the photo row and no real type id — nothing to save
                // against; skip rather than writing an orphaned row.
                continue;
            }

            DB::table('enclosure_masters')->updateOrInsert(
                $typeId
                    ? [
                        'program_id' => $req->program_id,
                        'semester_no' => $req->semester_no,
                        'admission_mode' => $req->admission_mode,
                        'enclosure_type_id' => $typeId,
                    ]
                    : [
                        'program_id' => $req->program_id,
                        'semester_no' => $req->semester_no,
                        'admission_mode' => $req->admission_mode,
                        'enclosure_type_id' => null,
                        'document_name' => 'Attach Color Photographs',
                    ],
                [
                    // Denormalized display copy — see enclosureMasterStore().
                    'document_name' => $typeId ? ($typeNames[$typeId] ?? null) : 'Attach Color Photographs',
                    'condition' => $row['condition'] ?? null,
                    'enclose' => !empty($row['enclose']),
                    'scan_copy' => !empty($row['scan_copy']),
                    'photo_count' => $row['photo_count'] ?? null,
                    'is_required' => (($row['condition'] ?? '') === 'Mandatory'),
                    'updated_at' => now(),
                ]
            );
        }

        return response()->json(['message' => 'Saved ' . count($req->rows) . ' document rule(s).']);
    }

    // 4. Fee Head Master ──────────────────────────────────────────────
    public function feeHeadIndex()
    {
        return response()->json(FeeHead::orderBy('name')->get());
    }

    public function feeHeadStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'name' => 'required|string|max:255|unique:fee_heads,name',
            'in_favor_of' => 'required|in:College,University,Government',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $fh = FeeHead::create($req->only(['name', 'in_favor_of']));
        return response()->json($fh, 201);
    }

    public function feeHeadUpdate(Request $req, $id)
    {
        FeeHead::findOrFail($id)->update($req->only(['name', 'in_favor_of']));
        return response()->json(['message' => 'Updated.']);
    }

    public function feeHeadDestroy($id)
    {
        FeeHead::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private const EXAM_MODE_TO_ADMISSION_TYPE = [
        'Regular' => 'regular',
        'Back Paper' => 'back_paper',
        'Upgrade' => 'upgrade',
    ];

    /** Categories a fee_structures row can exist under — one row per category, per configuration. */
    private const FEE_CATEGORIES = ['gen', 'obc', 'sc', 'st', 'ews'];

    public function feeStructureIndex(Request $req)
    {
        $admissionType = self::EXAM_MODE_TO_ADMISSION_TYPE[$req->exam_mode] ?? $req->exam_mode;

        $programClass = DB::table('programs')->where('id', $req->program_id)->value('short_name');

        $rows = DB::table('fee_structures as fs')
            ->select(
                'fs.id', 'fs.fee_ref_id', 'fs.organization_id', 'fs.program_id', 'fs.semester_no',
                'fs.academic_year', 'fs.admission_type', 'fs.category', 'fs.amount_json', 'fs.term',
                'fs.sdpgc_student', 'fs.ddu_affiliated', 'fs.in_favor_of', 'fs.late_fine_per_day', 'fs.due_date',
                'fs.is_active'
            )
            ->where('fs.program_id', $req->program_id)
            ->where('fs.academic_year', $req->session_year)
            ->where('fs.semester_no', $req->semester_no)
            ->where('fs.admission_type', $admissionType)
            ->when($req->has('sdpgc_student'), fn($q) => $q->where('fs.sdpgc_student', $req->boolean('sdpgc_student')))
            ->when($req->has('ddu_affiliated'), fn($q) => $q->where('fs.ddu_affiliated', $req->boolean('ddu_affiliated')))
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['rows' => [], 'category_refs' => (object) []]);
        }

        $feeHeadNames = DB::table('fee_heads')->pluck('name', 'id');
        $first = $rows->first();

        // fee_head_id -> "gender_category" -> amount, built by combining
        // every category-row's gender-nested amount_json.
        $flat = [];
        $categoryRefs = [];
        foreach ($rows as $row) {
            $categoryRefs[$row->category] = $row->fee_ref_id;
            $amountJson = $row->amount_json ? json_decode($row->amount_json, true) : [];
            foreach ((array) $amountJson as $gender => $heads) {
                foreach ((array) $heads as $feeHeadId => $amount) {
                    $flat[$feeHeadId]["{$gender}_{$row->category}"] = (float) $amount;
                }
            }
        }

        $out = [];
        foreach ($flat as $feeHeadId => $amounts) {
            $out[] = [
                'fee_head_id'    => (int) $feeHeadId,
                'fee_head'       => $feeHeadNames[$feeHeadId] ?? "Fee Head #{$feeHeadId}",
                'program_id'     => $first->program_id,
                'course'         => $programClass,
                'semester_no'    => $first->semester_no,
                'academic_year'  => $first->academic_year,
                'admission_type' => $first->admission_type,
                'amounts'        => $amounts,
                'term'           => $first->term,
                'in_favor_of'    => $first->in_favor_of,
                'sdpgc_student'  => $first->sdpgc_student,
                'ddu_affiliated' => $first->ddu_affiliated,
                'is_active'      => $first->is_active,
            ];
        }

        return response()->json(['rows' => $out, 'category_refs' => $categoryRefs]);
    }

    public function feeStructureStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'fee_head_id' => 'required|exists:fee_heads,id',
            'session_year' => 'required|string',
            'semester_no' => 'required|string',
            'exam_mode' => 'required|in:Regular,Back Paper,Upgrade',
            'term' => 'required|in:Admission,Semester Registration',
            'amounts' => 'required|array',
            // PG/B.Ed.-only pass-out-source flags. Absent/false for every
            // other program level — see 2026_08_15_090000_add_pass_out_
            // flags_to_fee_structures.php
            'sdpgc_student' => 'nullable|boolean',
            'ddu_affiliated' => 'nullable|boolean',
            'in_favor_of' => 'nullable|in:College,University,Government',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $orgId = DB::table('programs')->where('id', $req->program_id)->value('organization_id');
        $program = DB::table('programs')->where('id', $req->program_id)->first();

        $baseKey = [
            'program_id' => $req->program_id,
            'academic_year' => $req->session_year,
            'semester_no' => $req->semester_no,
            'admission_type' => self::EXAM_MODE_TO_ADMISSION_TYPE[$req->exam_mode] ?? 'regular',
            'sdpgc_student' => $req->boolean('sdpgc_student'),
            'ddu_affiliated' => $req->boolean('ddu_affiliated'),
        ];

        // Normalize the incoming amounts (e.g. "Male_Gen" -> gender=male,
        // category=gen) and group by category — drop malformed keys rather
        // than guessing.
        $byCategory = [];
        foreach ($req->amounts as $genderCatKey => $value) {
            $parts = explode('_', (string) $genderCatKey, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$gender, $category] = $parts;
            $gender = strtolower($gender);
            $category = strtolower($category);
            if (!in_array($category, self::FEE_CATEGORIES, true)) {
                continue; // unknown category code — skip rather than create a stray row
            }
            $byCategory[$category][$gender] = (float) $value;
        }

        if (empty($byCategory)) {
            return response()->json(['message' => 'No valid gender/category amounts were provided.'], 422);
        }

        $saved = 0;
        foreach ($byCategory as $category => $genderValues) {
            $rowKey = $baseKey + ['category' => $category];

            $existing = DB::table('fee_structures')->where($rowKey)->first();
            $amountJson = $existing && $existing->amount_json ? json_decode($existing->amount_json, true) : [];
            $amountJson = is_array($amountJson) ? $amountJson : [];

            foreach ($genderValues as $gender => $value) {
                $amountJson[$gender][(string) $req->fee_head_id] = $value;
            }

            DB::table('fee_structures')->updateOrInsert(
                $rowKey,
                [
                    'organization_id' => $orgId,
                    'term' => $req->term,
                    'amount_json' => json_encode($amountJson),
                    'in_favor_of' => $req->in_favor_of,
                    'updated_at' => now(),
                ]
            );

            // fee_ref_id: a real, DB-backed, unique fee reference — one per
            // (course + category) row, generated once when the row first
            // exists, left untouched on later edits. No gender component —
            // see AdmissionNumberService::feeRefId() header for why.
            $row = DB::table('fee_structures')->where($rowKey)->first();
            if ($row && !$row->fee_ref_id) {
                $feeRefId = app(\App\Services\AdmissionNumberService::class)
                    ->feeRefId('fee_structures', 'fee_ref_id', $program, $category);
                DB::table('fee_structures')->where('id', $row->id)->update(['fee_ref_id' => $feeRefId]);
            }
            $saved++;
        }

        return response()->json(['message' => "Fee structure saved ({$saved} category row" . ($saved === 1 ? '' : 's') . ")."]);
    }

    // Copies exactly the configuration currently loaded on the fee-structure
    // page (one program plus semester plus exam mode) from one academic
    // year forward into a later one.
    public function feeStructureCopyYear(Request $req)
    {
        $v = Validator::make($req->all(), [
            'from_year' => 'required|string',
            'to_year' => 'required|string',
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'required|integer',
            'exam_mode' => 'required|in:Regular,Back Paper,Upgrade',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        // Academic years are stored "YYYY-YYYY" — compare the leading year
        // number so a copy can only move forward in time.
        $fromStartYear = (int) substr($req->from_year, 0, 4);
        $toStartYear = (int) substr($req->to_year, 0, 4);
        if ($toStartYear <= $fromStartYear) {
            return response()->json(['message' => 'Copy target must be a later academic year than the source year — pick the next year, not the same one or an earlier one.'], 422);
        }

        $admissionType = self::EXAM_MODE_TO_ADMISSION_TYPE[$req->exam_mode] ?? $req->exam_mode;

        $rows = DB::table('fee_structures')
            ->where('academic_year', $req->from_year)
            ->where('program_id', $req->program_id)
            ->where('semester_no', $req->semester_no)
            ->where('admission_type', $admissionType)
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No fee structure found for that class, semester and exam mode in the source year.'], 422);
        }

        $program = DB::table('programs')->where('id', $req->program_id)->first();
        $copied = 0;

        foreach ($rows as $r) {
            $key = [
                'program_id' => $r->program_id,
                'academic_year' => $req->to_year,
                'semester_no' => $r->semester_no,
                'admission_type' => $r->admission_type,
                'category' => $r->category,
                'sdpgc_student' => $r->sdpgc_student,
                'ddu_affiliated' => $r->ddu_affiliated,
            ];

            DB::table('fee_structures')->updateOrInsert(
                $key,
                [
                    'organization_id' => $r->organization_id,
                    'term' => $r->term,
                    'amount_json' => $r->amount_json,
                    'in_favor_of' => $r->in_favor_of,
                    'updated_at' => now(),
                ]
            );

            // Fresh fee_ref_id for the new year's row — copying doesn't
            // reuse last year's reference number.
            $row = DB::table('fee_structures')->where($key)->first();
            if ($row && !$row->fee_ref_id) {
                $feeRefId = app(\App\Services\AdmissionNumberService::class)
                    ->feeRefId('fee_structures', 'fee_ref_id', $program, $r->category);
                DB::table('fee_structures')->where('id', $row->id)->update(['fee_ref_id' => $feeRefId]);
            }
            $copied++;
        }

        return response()->json(['message' => "Copied {$copied} fee structure(s), {$req->from_year} → {$req->to_year}."]);
    }

    public function registrationFeeCopyYear(Request $req)
    {
        $v = Validator::make($req->all(), [
            'from_year' => 'required|string',
            'to_year' => 'required|string|different:from_year',
            'program_id' => 'nullable|exists:programs,id',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $rows = DB::table('registration_fees')
            ->where('session_year', $req->from_year)
            ->when($req->program_id, fn($q) => $q->where('program_id', $req->program_id))
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'message' => "No registration fees found for {$req->from_year}" .
                    ($req->program_id ? ' for this class.' : '.'),
            ], 404);
        }

        $count = 0;
        foreach ($rows as $r) {
            DB::table('registration_fees')->updateOrInsert(
                [
                    'program_id' => $r->program_id,
                    'session_year' => $req->to_year,
                    'semester_no' => $r->semester_no,
                    'registration_mode' => $r->registration_mode,
                ],
                ['amounts' => $r->amounts, 'updated_at' => now()]
            );
            $count++;
        }

        return response()->json([
            'message' => "Copied {$count} registration fee row(s): {$req->from_year} → {$req->to_year}.",
        ]);
    }

    // 6. Registration Fee ─────────────────────────────────────────────
    public function registrationFeeIndex(Request $req)
    {
        return response()->json(
            DB::table('registration_fees')
                ->when($req->program_id, fn($q) => $q->where('program_id', $req->program_id))
                ->when($req->session_year, fn($q) => $q->where('session_year', $req->session_year))
                ->get()
        );
    }

    public function registrationFeeStore(Request $req)
    {
        DB::table('registration_fees')->updateOrInsert(
            [
                'program_id' => $req->program_id,
                'session_year' => $req->session_year,
                'semester_no' => $req->semester_no,
                'registration_mode' => $req->registration_mode
            ],
            array_merge($req->only(['amounts']), ['updated_at' => now()])
        );
        return response()->json(['message' => 'Registration fee saved.']);
    }

    public function registrationFeeDestroy($id)
    {
        DB::table('registration_fees')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 7. Back Paper Schedule ──────────────────────────────────────────
    public function backPaperScheduleIndex(Request $req)
    {
        return response()->json(
            DB::table('back_paper_schedules as b')
                ->join('programs as p', 'p.id', 'b.program_id')
                ->select('b.*', 'p.short_name as class_name')
                ->when($req->session_year, fn($q) => $q->where('b.session_year', $req->session_year))
                ->orderBy('b.created_at', 'desc')
                ->get()
        );
    }

    public function backPaperScheduleStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester' => 'required|string',
            'session_year' => 'required|string',
            'start_from' => 'required|date',
            'end_on' => 'required|date|after:start_from',
            'late_fee_applicable' => 'required|boolean',
            'late_fee' => 'nullable|numeric|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('back_paper_schedules')->insertGetId(array_merge($req->all(), [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Back paper schedule saved.'], 201);
    }

    public function backPaperScheduleUpdate(Request $req, $id)
    {
        DB::table('back_paper_schedules')->where('id', $id)->update(array_merge(
            $req->only(['program_id', 'semester', 'session_year', 'start_from', 'end_on', 'late_fee_applicable', 'late_fee']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function backPaperScheduleDestroy($id)
    {
        DB::table('back_paper_schedules')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // ══════════════════════════════════════════════════════════════════
    // COURSE SETTINGS
    // ══════════════════════════════════════════════════════════════════

    private const CLASS_LEVEL_TO_ENUM = ['B.Ed' => 'BEd'];

    /** SAMARTH code: digits only, unique among live (non soft-deleted)
     * programs — mirrors the partial unique index on programs.samarth_code. */
    private function samarthCodeRules($ignoreId = null): array
    {
        return [
            'required', 'string', 'max:20', 'regex:/^[0-9]+$/',
            Rule::unique('programs', 'samarth_code')->ignore($ignoreId)->whereNull('deleted_at'),
        ];
    }

    private const SAMARTH_CODE_MESSAGES = [
        'samarth_code.required' => 'SAMARTH Code is required.',
        'samarth_code.regex' => 'SAMARTH Code must contain digits only.',
        'samarth_code.unique' => 'This SAMARTH Code is already assigned to another course.',
    ];

    /**
     * Course list. Inactive courses are excluded by default — this endpoint
     * feeds every course dropdown in the app. Course Master (and the
     * name → SAMARTH-code lookup for existing records) pass ?with_inactive=1.
     */
    public function classMasterIndex(Request $req)
    {
        return response()->json(
            Program::query()
                ->when(!$req->boolean('with_inactive'), fn($q) => $q->where('is_active', true))
                ->orderBy('short_name')->get()->map(function ($p) {
                    $p->status = $p->is_active ? 'Active' : 'Inactive';
                    return $p;
                })
        );
    }

    public function classMasterStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'level' => 'required|in:UG,PG,B.Ed,BEd,Diploma,Certificate',
            'approval_type' => 'required|in:Under Finance,Self Finance',
            'short_name' => 'required|string|max:20|unique:programs,short_name',
            'samarth_code' => $this->samarthCodeRules(),
            'full_name' => 'required|string|max:255',
            'duration_years' => 'required|integer|min:1',
            'exam_mode' => 'required|in:Regular,Back Paper',
            'total_semesters' => 'required|integer|min:1',
            'status' => 'required|in:Active,Inactive',
        ], self::SAMARTH_CODE_MESSAGES);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $orgId = DB::table('organizations')->where('is_active', true)->value('id');

        $p = Program::create([
            'organization_id' => $orgId,
            'name' => $req->full_name,
            'full_name' => $req->full_name,
            'short_name' => $req->short_name,
            'samarth_code' => $req->samarth_code,
            'code' => $this->generateProgramCode($req->short_name),
            'level' => self::CLASS_LEVEL_TO_ENUM[$req->level] ?? $req->level,
            'duration_years' => $req->duration_years,
            'total_semesters' => $req->total_semesters,
            'approval_type' => $req->approval_type,
            'is_self_finance' => $req->approval_type === 'Self Finance',
            'exam_mode' => $req->exam_mode,
            'is_active' => $req->status === 'Active',
        ]);
        $p->status = $p->is_active ? 'Active' : 'Inactive';
        return response()->json($p, 201);
    }

    public function classMasterUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'level' => 'required|in:UG,PG,B.Ed,BEd,Diploma,Certificate',
            'approval_type' => 'required|in:Under Finance,Self Finance',
            'short_name' => 'required|string|max:20|unique:programs,short_name,' . $id,
            'samarth_code' => $this->samarthCodeRules($id),
            'full_name' => 'required|string|max:255',
            'duration_years' => 'required|integer|min:1',
            'exam_mode' => 'required|in:Regular,Back Paper',
            'total_semesters' => 'required|integer|min:1',
            'status' => 'required|in:Active,Inactive',
        ], self::SAMARTH_CODE_MESSAGES);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        Program::findOrFail($id)->update([
            'name' => $req->full_name,
            'full_name' => $req->full_name,
            'short_name' => $req->short_name,
            'samarth_code' => $req->samarth_code,
            'level' => self::CLASS_LEVEL_TO_ENUM[$req->level] ?? $req->level,
            'duration_years' => $req->duration_years,
            'total_semesters' => $req->total_semesters,
            'approval_type' => $req->approval_type,
            'is_self_finance' => $req->approval_type === 'Self Finance',
            'exam_mode' => $req->exam_mode,
            'is_active' => $req->status === 'Active',
        ]);
        return response()->json(['message' => 'Updated.']);
    }

    /** programs.code is unique + NOT NULL but never collected by the Class
     * Master form — derive a stable code from short_name and disambiguate
     * on collision. */
    private function generateProgramCode(string $shortName): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $shortName));
        $base = $base !== '' ? substr($base, 0, 15) : 'PRG';
        $code = $base;
        $i = 1;
        while (DB::table('programs')->where('code', $code)->exists()) {
            $i++;
            $code = substr($base, 0, 18) . $i;
        }
        return $code;
    }

    /** Tables holding student records for a course. Every one of them has
     * ON DELETE CASCADE on program_id, so a hard delete of the course would
     * silently wipe them — deletion is refused while any of these has rows. */
    private const COURSE_STUDENT_TABLES = [
        'admissions' => 'admissions',
        'student_applications' => 'applications',
        'direct_registrations' => 'registrations',
        'semester_registrations' => 'semester registrations',
        'counselling_reports' => 'counselling records',
        'examinations' => 'examinations',
    ];

    /**
     * Permanent delete (not a soft delete). Requires the logged-in user's
     * password. Refused while the course has student records — set it
     * Inactive instead. Course setup rows (subjects, papers, fee structure,
     * schedules…) are removed with it by the database cascade.
     */
    public function classMasterDestroy(Request $req, $id)
    {
        $v = Validator::make($req->all(), ['password' => 'required|string']);
        if ($v->fails())
            return response()->json(['errors' => ['password' => ['Password is required to delete a course.']]], 422);

        $user = $req->user();
        if (!$user || !\Illuminate\Support\Facades\Hash::check($req->password, $user->password))
            return response()->json(['errors' => ['password' => ['Incorrect password.']]], 422);

        $program = Program::findOrFail($id);

        $blocking = [];
        foreach (self::COURSE_STUDENT_TABLES as $table => $label) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($table)
                || !\Illuminate\Support\Facades\Schema::hasColumn($table, 'program_id')) {
                continue;
            }
            $count = DB::table($table)->where('program_id', $id)->count();
            if ($count > 0) {
                $blocking[] = "{$count} {$label}";
            }
        }
        if ($blocking) {
            return response()->json([
                'message' => "Cannot delete {$program->short_name}: it has " . implode(', ', $blocking)
                    . '. Set the course to Inactive instead.',
            ], 409);
        }

        $program->forceDelete();
        return response()->json(['message' => 'Course deleted permanently.']);
    }

    // 9. Semester Master (READ-ONLY) ──────────────────────────────────
    public function semesterMasterIndex()
    {
        return response()->json(
            SemesterMaster::active()->map(fn($s) => [
                'id'            => $s->id,
                'semester_num'  => $s->semester_num,
                'semester_name' => $s->semester_name,
                'label'         => $s->label,   // "Semester 1 (ODD)"
                'status'        => $s->status,
            ])->values()
        );
    }

    // 10. Subject Master ──────────────────────────────────────────────
    public function subjectMasterIndex(Request $req)
    {
        return response()->json(
            Subject::with('program')
                ->when($req->program_id, fn($q) => $q->where('program_id', $req->program_id))
                ->orderBy('name')->get()
        );
    }

    public function subjectMasterStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'name' => 'required|string|max:255',
            'has_practical' => 'nullable|boolean',
            'practical_fee' => 'nullable|numeric|min:0',
            'permission_type' => 'required|in:Finance,Self Finance',
            'additional_fee_applicable' => 'nullable|boolean',
            'additional_fee' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $s = Subject::create([
            'program_id' => $req->program_id,
            'code' => $this->generateSubjectCode($req->program_id, $req->name),
            'name' => $req->name,
            'semester_no' => 1,           // not part of this screen — see comment above
            'type' => 'compulsory',       // not part of this screen — see comment above
            'paper_type' => $req->permission_type === 'Self Finance' ? 'self_finance' : 'regular',
            'is_active' => filter_var($req->is_active ?? true, FILTER_VALIDATE_BOOLEAN),
            'has_practical' => filter_var($req->has_practical ?? false, FILTER_VALIDATE_BOOLEAN),
            'practical_fee' => $req->has_practical ? $req->practical_fee : null,
            'additional_fee_applicable' => filter_var($req->additional_fee_applicable ?? false, FILTER_VALIDATE_BOOLEAN),
            'additional_fee' => $req->additional_fee_applicable ? $req->additional_fee : null,
        ]);
        return response()->json($s, 201);
    }

    public function subjectMasterUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'name' => 'required|string|max:255',
            'has_practical' => 'nullable|boolean',
            'practical_fee' => 'nullable|numeric|min:0',
            'permission_type' => 'required|in:Finance,Self Finance',
            'additional_fee_applicable' => 'nullable|boolean',
            'additional_fee' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        Subject::findOrFail($id)->update([
            'program_id' => $req->program_id,
            'name' => $req->name,
            'paper_type' => $req->permission_type === 'Self Finance' ? 'self_finance' : 'regular',
            'is_active' => filter_var($req->is_active ?? true, FILTER_VALIDATE_BOOLEAN),
            'has_practical' => filter_var($req->has_practical ?? false, FILTER_VALIDATE_BOOLEAN),
            'practical_fee' => $req->has_practical ? $req->practical_fee : null,
            'additional_fee_applicable' => filter_var($req->additional_fee_applicable ?? false, FILTER_VALIDATE_BOOLEAN),
            'additional_fee' => $req->additional_fee_applicable ? $req->additional_fee : null,
        ]);
        return response()->json(['message' => 'Updated.']);
    }

    /** subjects.code is unique + NOT NULL but this screen doesn't collect
     * one — derive from program short_name + subject name, disambiguate on
     * collision (same pattern as generateProgramCode()). */
    private function generateSubjectCode($programId, string $name): string
    {
        $shortName = DB::table('programs')->where('id', $programId)->value('short_name') ?? 'SUB';
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $shortName . '-' . $name));
        $base = $base !== '' ? substr($base, 0, 25) : 'SUBJECT';
        $code = $base;
        $i = 1;
        while (DB::table('subjects')->where('code', $code)->exists()) {
            $i++;
            $code = substr($base, 0, 28) . $i;
        }
        return $code;
    }

    public function subjectMasterDestroy($id)
    {
        Subject::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 11. Allotted Subject Master ─────────────────────────────────────
    public function allottedSubjectIndex(Request $req)
    {
        // Was missing p.level / p.approval_type — the frontend table renders
        // both columns but they always showed "-" since the query never
        // selected them.
        return response()->json(
            DB::table('allotted_subjects as a')
                ->join('programs as p', 'p.id', 'a.program_id')
                ->join('subjects as s', 's.id', 'a.subject_id')
                ->select(
                    'a.*', 'p.short_name as course', 'p.full_name', 'p.level', 'p.approval_type',
                    's.name as subject_name', 's.has_practical', 's.practical_fee'
                )
                ->when($req->program_id, fn($q) => $q->where('a.program_id', $req->program_id))
                ->get()
        );
    }

    public function allottedSubjectStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'subject_id' => 'required|exists:subjects,id',
            'permission_type' => 'required|in:Finance,Self Finance',
            'for_regular' => 'required|boolean',
            'for_private' => 'required|boolean',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('allotted_subjects')->insertGetId(array_merge($req->all(), [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Allotted subject saved.'], 201);
    }

    public function allottedSubjectDestroy($id)
    {
        DB::table('allotted_subjects')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 12. Subject Paper Master ────────────────────────────────────────
    // subject_papers real columns: program_id, subject_id, session_year,
    // semester_no, paper_type, paper_name, group_no, max_marks, min_marks,
    // plus paper_code
    // paper_type is Theory / Practical, set per paper row. The request-level
    // paper_type (Configuration panel) is only the default for rows that
    // don't carry their own.
    private const PAPER_TYPES = ['Theory', 'Practical'];

    private function programIsBsc($program): bool
    {
        if (!$program) return false;
        $norm = strtoupper(preg_replace('/[^A-Za-z]/', '', $program->short_name ?? ''));
        return str_contains($norm, 'BSC');
    }

    public function subjectPaperIndex(Request $req)
    {
        return response()->json(
            DB::table('subject_papers as sp')
                // left join, not inner — a stale/re-pointed program_id or
                // subject_id must never silently hide an otherwise-real
                // subject_papers row from this list.
                ->leftJoin('programs as p', 'p.id', 'sp.program_id')
                ->leftJoin('subjects as s', 's.id', 'sp.subject_id')
                ->select('sp.*', 'p.short_name as course', 'p.samarth_code', 's.name as subject_name')
                ->when($req->program_id, fn($q) => $q->where('sp.program_id', $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('sp.semester_no', $req->semester_no))
                ->when($req->session_year, fn($q) => $q->where('sp.session_year', $req->session_year))
                ->when($req->paper_type, fn($q) => $q->where('sp.paper_type', $req->paper_type))
                ->when($req->subject_id, fn($q) => $q->where('sp.subject_id', $req->subject_id))
                ->orderBy('sp.group_label')
                ->orderBy('sp.id')
                ->get()
        );
    }

    public function subjectPaperStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'subject_id' => 'required|exists:subjects,id',
            'session_year' => 'required|string',
            'semester_no' => 'required|string',
            'paper_type' => ['nullable', Rule::in(self::PAPER_TYPES)],
            'group_label' => 'nullable|string|max:50',
            'papers' => 'required|array|min:1',
            'papers.*.paper_code' => 'nullable|string|max:50',
            'papers.*.paper_name' => 'required|string',
            'papers.*.paper_type' => ['nullable', Rule::in(self::PAPER_TYPES)],
            'papers.*.max_marks' => 'nullable|integer|min:1',
            'papers.*.min_marks' => 'nullable|integer|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $program = DB::table('programs')->find($req->program_id);
        $isBsc = $this->programIsBsc($program);

        if ($isBsc && !$req->group_label) {
            return response()->json(['errors' => ['group_label' => ['Group Name is required for BSc electives.']]], 422);
        }
        $groupLabel = $isBsc ? trim($req->group_label) : null;

        $rows = [];
        foreach ($req->papers as $p) {
            $rows[] = [
                'program_id' => $req->program_id,
                'subject_id' => $req->subject_id,
                'session_year' => $req->session_year,
                'semester_no' => $req->semester_no,
                'paper_type' => ($p['paper_type'] ?? null) ?: ($req->paper_type ?: 'Theory'),
                'paper_code' => $p['paper_code'] ?? null,
                'paper_name' => $p['paper_name'],
                'group_no' => null,
                'group_label' => $groupLabel,
                'max_marks' => $p['max_marks'] ?? 100,
                'min_marks' => $p['min_marks'] ?? 33,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('subject_papers')->insert($rows);

        $msg = 'Saved ' . count($rows) . ' paper(s)' . ($groupLabel ? " under Group \"{$groupLabel}\"." : '.');
        return response()->json(['message' => $msg, 'group_label' => $groupLabel], 201);
    }

    public function subjectPaperUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'paper_code' => 'nullable|string|max:50',
            'paper_name' => 'required|string',
            'paper_type' => ['nullable', Rule::in(self::PAPER_TYPES)],
            'group_label' => 'nullable|string|max:50',
            'max_marks' => 'nullable|integer|min:1',
            'min_marks' => 'nullable|integer|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $update = [
            'paper_code' => $req->paper_code,
            'paper_name' => $req->paper_name,
            'max_marks' => $req->max_marks ?? 100,
            'min_marks' => $req->min_marks ?? 33,
            'updated_at' => now(),
        ];
        if ($req->has('group_label')) {
            $update['group_label'] = $req->group_label ?: null;
        }
        // Only touch paper_type when the caller actually sent one.
        if ($req->filled('paper_type')) {
            $update['paper_type'] = $req->paper_type;
        }

        DB::table('subject_papers')->where('id', $id)->update($update);
        return response()->json(['message' => 'Updated.']);
    }

    public function subjectPaperDestroy($id)
    {
        DB::table('subject_papers')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Real print — a college office needs an actual printable Subject Paper
     * Master sheet (Class / Semester / Session header, then Group 1 / 2 / 3
     * sections with Subject, Paper Code, Paper Name, Max, Min), not just a
     * browser print of the on-screen grid.
     */
    public function subjectPaperPrint(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'required|string',
            'session_year' => 'required|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $program = DB::table('programs')->find($req->program_id);
        $org = DB::table('organizations')->where('is_active', true)->first();

        $isBsc = $this->programIsBsc($program);

        $rows = DB::table('subject_papers as sp')
            ->leftJoin('subjects as s', 's.id', 'sp.subject_id')
            ->select('sp.*', 's.name as subject_name')
            ->where('sp.program_id', $req->program_id)
            ->where('sp.semester_no', $req->semester_no)
            ->where('sp.session_year', $req->session_year)
            ->orderBy('sp.group_label')
            ->orderBy('sp.id')
            ->get();

        // Group is only a real concept for BSc electives — everything else
        // prints as one flat list under a single unlabeled bucket.
        $groups = $isBsc
            ? $rows->groupBy(fn ($r) => $r->group_label ?: 'Ungrouped')
            : collect(['' => $rows]);

        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.subject-paper-master', [
                'program' => $program,
                'org' => $org,
                'semesterNo' => $req->semester_no,
                'sessionYear' => $req->session_year,
                'groups' => $groups,
                'showGroups' => $isBsc,
            ])->setPaper('a4');

            return response()->streamDownload(
                fn () => print($pdf->output()),
                "Subject-Paper-Master-" . Program::fileSlug($program) . "-Sem{$req->semester_no}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Subject paper master print failed: ' . $e->getMessage());
            return response()->json(['message' => 'Could not generate the print sheet.'], 500);
        }
    }

    // 13. Subject Seat in Class ───────────────────────────────────────
    public function subjectSeatIndex(Request $req)
    {
        return response()->json(
            DB::table('subject_seats as ss')
                ->join('programs as p', 'p.id', 'ss.program_id')
                ->join('subjects as s', 's.id', 'ss.subject_id')
                ->select('ss.*', 'p.short_name as course', 'p.full_name', 's.name as subject_name')
                ->when($req->program_id, fn($q) => $q->where('ss.program_id', $req->program_id))
                ->get()
        );
    }

    public function subjectSeatStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'subject_id' => 'required|exists:subjects,id',
            'allotted_seat' => 'required|integer',
            'order_ref' => 'nullable|string',
            'varg_bridhi' => 'nullable|integer',
            'total_seat' => 'required|integer',
            'permission_type' => 'required|in:Finance,Self Finance,Temporary',
            'period_session' => 'nullable|string',
            'status' => 'required|in:Active,Inactive',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('subject_seats')->updateOrInsert(
            ['program_id' => $req->program_id, 'subject_id' => $req->subject_id],
            array_merge($req->except(['program_id', 'subject_id']), ['updated_at' => now()])
        );
        return response()->json(['message' => 'Seat configuration saved.']);
    }

    public function subjectSeatDestroy($id)
    {
        DB::table('subject_seats')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // 14. Subject Selection Master ────────────────────────────────────
    // Grouped view (A, B, C …) for the builder + grid.
    public function subjectSelectionIndex(Request $req)
    {
        $groups = $this->groupedSelections($req->program_id, $req->semester_no);
        $class  = DB::table('programs')->where('id', $req->program_id)->value('short_name');

        return response()->json([
            'course'       => $class,
            'semester_no'  => $req->semester_no,
            'total_groups' => count($groups),
            'groups'       => $groups,
        ]);
    }

    /**
     * Save one letter-group and its subjects (upsert — replaces the group's rows).
     * Payload: { program_id, semester_no, group_label, group_name?, max_select,
     *            min_select, is_compulsory?, subjects: [{subject_id, sort_order?}] }
     */
    public function subjectSelectionStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id'          => 'required|exists:programs,id',
            'semester_no'         => 'required|string',
            'group_label'         => 'required|string|max:2',
            'group_name'          => 'nullable|string',
            'max_select'          => 'required|integer|min:1',
            'min_select'          => 'required|integer|min:0',
            'is_compulsory'       => 'nullable|boolean',
            'subjects'            => 'required|array|min:1',
            'subjects.*.subject_id' => 'required|exists:subjects,id',
            'subjects.*.sort_order' => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $label   = strtoupper($req->group_label);
        $groupNo = ord($label) - 64;   // A -> 1 (kept for reg/adm compatibility)

        DB::transaction(function () use ($req, $label, $groupNo) {
            // Replace the whole group.
            DB::table('subject_selections')
                ->where('program_id', $req->program_id)
                ->where('semester_no', $req->semester_no)
                ->where('group_label', $label)
                ->delete();

            $rows = [];
            foreach ($req->subjects as $i => $sub) {
                $rows[] = [
                    'program_id'    => $req->program_id,
                    'semester_no'   => $req->semester_no,
                    'subject_id'    => $sub['subject_id'],
                    'group_no'      => $groupNo,
                    'group_label'   => $label,
                    'group_name'    => $req->group_name,
                    'max_select'    => $req->max_select,
                    'min_select'    => $req->min_select,
                    'is_compulsory' => (bool) ($req->is_compulsory ?? false),
                    'sort_order'    => $sub['sort_order'] ?? ($i + 1),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }
            DB::table('subject_selections')->insert($rows);
        });

        return response()->json(['message' => "Group {$label} saved."], 201);
    }

    // Delete a single subject row (per-subject X action).
    public function subjectSelectionDestroy($id)
    {
        DB::table('subject_selections')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // Delete a whole group: ?program_id=&semester_no=&group_label=
    public function subjectSelectionGroupDestroy(Request $req)
    {
        DB::table('subject_selections')
            ->where('program_id', $req->program_id)
            ->where('semester_no', $req->semester_no)
            ->where('group_label', strtoupper((string) $req->group_label))
            ->delete();
        return response()->json(['message' => 'Group deleted.']);
    }

    /**
     * Real print — an actual PDF of the Subject Selection Master groups
     * (Group A / B / C … with their subjects and Max/Min Select), not a
     * browser print of the on-screen builder.
     */
    public function subjectSelectionPrint(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'nullable|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $program = DB::table('programs')->find($req->program_id);
        $org = DB::table('organizations')->where('is_active', true)->first();
        $groups = $this->groupedSelections($req->program_id, $req->semester_no);

        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.subject-selection-master', [
                'program' => $program,
                'org' => $org,
                'semesterNo' => $req->semester_no,
                'groups' => $groups,
            ])->setPaper('a4');

            return response()->streamDownload(
                fn () => print($pdf->output()),
                "Subject-Selection-Master-" . Program::fileSlug($program) . ".pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Subject selection master print failed: ' . $e->getMessage());
            return response()->json(['message' => 'Could not generate the print sheet.'], 500);
        }
    }

    // Groupwise subjects for the registration & application dropdowns.
    // Auth version (college portal).
    public function subjectGroups(Request $req)
    {
        return response()->json([
            'groups' => $this->groupedSelections($req->program_id, $req->semester_no),
        ]);
    }

    // Public version (registration form is public — no auth).
    public function publicSubjectGroups(Request $req)
    {
        return response()->json([
            'groups' => $this->groupedSelections(
                $req->query('program_id'),
                $req->query('semester_no', $req->query('semester'))
            ),
        ]);
    }

    /** Shared: build the A/B/C group structure with its subjects. */
    private function groupedSelections($programId, $semesterNo): array
    {
        if (!$programId) {
            return [];
        }

        $rows = DB::table('subject_selections as sel')
            ->join('subjects as s', 's.id', '=', 'sel.subject_id')
            ->select(
                'sel.id', 'sel.subject_id', 'sel.group_label', 'sel.group_name',
                'sel.max_select', 'sel.min_select', 'sel.is_compulsory', 'sel.sort_order',
                's.name as subject_name', 's.code as subject_code'
            )
            ->where('sel.program_id', $programId)
            ->when($semesterNo, fn ($q) => $q->where('sel.semester_no', $semesterNo))
            ->orderBy('sel.group_label')->orderBy('sel.sort_order')
            ->get();

        return $rows->groupBy('group_label')->map(function ($items, $label) {
            $first = $items->first();
            return [
                'group_label'   => $label,
                'group_name'    => $first->group_name,
                'max_select'    => (int) $first->max_select,
                'min_select'    => (int) $first->min_select,
                'is_compulsory' => (bool) $first->is_compulsory,
                'subjects'      => $items->map(fn ($r) => [
                    'id'           => $r->id,
                    'subject_id'   => $r->subject_id,
                    'subject_name' => $r->subject_name,
                    'subject_code' => $r->subject_code,
                    'sort_order'   => $r->sort_order,
                ])->values(),
            ];
        })->values()->all();
    }

    // 15. Vocational & Co-Curriculum Paper Master ─────────────────────
    /**
     * Public list of vocational / co-curricular papers, used to populate the
     * Minor Subject dropdown on the (public) UG registration form.
     * GET /student/register/vocational-papers?program_id=&semester_no=&session_year=
     */
    public function publicVocationalPapers(Request $req)
    {
        $programId = $req->query('program_id');
        if (!$programId) {
            return response()->json(['papers' => []]);
        }

        $papers = DB::table('vocational_papers')
            ->where('program_id', $programId)
            ->when($req->query('semester_no'), fn ($q) => $q->where('semester_no', $req->query('semester_no')))
            ->when($req->query('session_year'), fn ($q) => $q->where('session_year', $req->query('session_year')))
            ->orderBy('group_no')->orderBy('paper_name')
            ->get(['id', 'paper_code', 'paper_name', 'group_no', 'group_name']);

        return response()->json(['papers' => $papers]);
    }

    public function vocationalPaperIndex(Request $req)
    {
        return response()->json(
            DB::table('vocational_papers as vp')
                ->leftJoin('programs as p', 'p.id', 'vp.program_id')
                ->select('vp.*', 'p.short_name as course')
                ->when($req->program_id, fn($q) => $q->where('vp.program_id', $req->program_id))
                ->when($req->semester_no, fn($q) => $q->where('vp.semester_no', $req->semester_no))
                ->when($req->session_year, fn($q) => $q->where('vp.session_year', $req->session_year))
                ->orderBy('vp.group_no')
                ->get()
        );
    }

    public function vocationalPaperStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'session_year' => 'required|string',
            'semester_no' => 'required|string',
            'group_no' => 'required|integer',
            'group_name' => 'required|string',
            'max_select' => 'nullable|integer|min:1',
            'min_select' => 'nullable|integer|min:0',
            'papers' => 'required|array|min:1',
            'papers.*.paper_code' => 'required|string|max:50',
            'papers.*.paper_name' => 'required|string',
            'papers.*.max_marks' => 'nullable|integer|min:1',
            'papers.*.min_marks' => 'nullable|integer|min:0',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $rows = [];
        foreach ($req->papers as $p) {
            $rows[] = [
                'program_id' => $req->program_id,
                'session_year' => $req->session_year,
                'semester_no' => $req->semester_no,
                'group_no' => $req->group_no,
                'group_name' => $req->group_name,
                'max_select' => $req->max_select ?? 1,
                'min_select' => $req->min_select ?? 1,
                'paper_code' => $p['paper_code'],
                'paper_name' => $p['paper_name'],
                'max_marks' => $p['max_marks'] ?? 100,
                'min_marks' => $p['min_marks'] ?? 33,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('vocational_papers')->insert($rows);

        return response()->json(['message' => 'Saved ' . count($rows) . " paper(s) under Group {$req->group_no}."], 201);
    }

    public function vocationalPaperUpdate(Request $req, $id)
    {
        $v = Validator::make($req->all(), [
            'group_name' => 'nullable|string',
            'max_select' => 'nullable|integer|min:1',
            'min_select' => 'nullable|integer|min:0',
            'paper_code' => 'nullable|string',
            'paper_name' => 'required|string',
            'max_marks' => 'nullable|integer',
            'min_marks' => 'nullable|integer',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        DB::table('vocational_papers')->where('id', $id)->update([
            'paper_code' => $req->paper_code,
            'paper_name' => $req->paper_name,
            'max_marks' => $req->max_marks ?? 100,
            'min_marks' => $req->min_marks ?? 33,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Updated.']);
    }

    public function vocationalPaperDestroy($id)
    {
        DB::table('vocational_papers')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Real print — an actual PDF of the Vocational & Co-Curriculum Paper
     * Master groups (Group 1 / 2 / 3 … with Paper Code/Name/Max/Min), not a
     * browser print of the on-screen grid.
     */
    public function vocationalPaperPrint(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'semester_no' => 'required|string',
            'session_year' => 'required|string',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $program = DB::table('programs')->find($req->program_id);
        $org = DB::table('organizations')->where('is_active', true)->first();

        $groups = DB::table('vocational_papers')
            ->where('program_id', $req->program_id)
            ->where('semester_no', $req->semester_no)
            ->where('session_year', $req->session_year)
            ->orderBy('group_no')
            ->orderBy('id')
            ->get()
            ->groupBy('group_no');

        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.vocational-paper-master', [
                'program' => $program,
                'org' => $org,
                'semesterNo' => $req->semester_no,
                'sessionYear' => $req->session_year,
                'groups' => $groups,
            ])->setPaper('a4');

            return response()->streamDownload(
                fn () => print($pdf->output()),
                "Vocational-Paper-Master-" . Program::fileSlug($program) . "-Sem{$req->semester_no}.pdf",
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Vocational paper master print failed: ' . $e->getMessage());
            return response()->json(['message' => 'Could not generate the print sheet.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // HOLIDAY CALENDAR
    // ══════════════════════════════════════════════════════════════════
    public function holidayIndex(Request $req)
    {
        return response()->json(
            DB::table('holiday_calendars')
                ->when($req->session_year, fn($q) => $q->where('session_year', $req->session_year))
                ->orderBy('leave_from')
                ->get()
        );
    }

    public function holidayStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'session_year' => 'required|string',
            'name' => 'required|string|max:255',
            'type' => 'required|in:Gazetted,Local,College Level,University Level',
            'leave_from' => 'required|date',
            'leave_days' => 'required|integer|min:1',
            'leave_till' => 'required|date',
            'leave_for' => 'required|in:All,Teaching Staff,Office Staff Only,Only Student',
            'sms_alert' => 'required|in:Before,Same Day,Immediate',
            'sms_days_before' => 'nullable|integer',
            'is_active' => 'required|boolean',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('holiday_calendars')->insertGetId(array_merge($req->all(), [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Holiday saved.'], 201);
    }

    public function holidayUpdate(Request $req, $id)
    {
        DB::table('holiday_calendars')->where('id', $id)->update(array_merge(
            $req->only(['name', 'type', 'leave_from', 'leave_days', 'leave_till', 'leave_for', 'sms_alert', 'sms_days_before', 'is_active']),
            ['updated_at' => now()]
        ));
        return response()->json(['message' => 'Updated.']);
    }

    public function holidayDestroy($id)
    {
        DB::table('holiday_calendars')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // ══════════════════════════════════════════════════════════════════
    // PRINT PERMISSION ON STUDENT PORTAL
    // ══════════════════════════════════════════════════════════════════
    public function printPermissionIndex()
    {
        return response()->json(DB::table('print_permissions')->get());
    }

    public function printPermissionUpdate(Request $req)
    {
        foreach ($req->permissions as $perm) {
            DB::table('print_permissions')->updateOrInsert(
                ['document_type' => $perm['document_type']],
                ['is_allowed' => $perm['is_allowed'], 'updated_at' => now()]
            );
        }
        return response()->json(['message' => 'Print permissions updated.']);
    }

    // ══════════════════════════════════════════════════════════════════
    // STATE SECURITY DEPOSIT
    // ══════════════════════════════════════════════════════════════════
    public function securityDepositIndex()
    {
        return response()->json(DB::table('state_security_deposits')->orderBy('state_name')->get());
    }

    public function securityDepositUpdate(Request $req, $id)
    {
        DB::table('state_security_deposits')->where('id', $id)->update([
            'deposit_required' => $req->deposit_required,
            'amount' => $req->amount,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Updated.']);
    }

    // ══════════════════════════════════════════════════════════════════
    // COUNSELLING REPORTED STUDENT DATA
    // ══════════════════════════════════════════════════════════════════
    public function counsellingIndex(Request $req)
    {
        return response()->json(
            DB::table('counselling_reports')
                ->when($req->program_id, fn($q) => $q->where('program_id', $req->program_id))
                ->when($req->session_year, fn($q) => $q->where('session_year', $req->session_year))
                ->orderBy('created_at', 'desc')
                ->paginate(20)
        );
    }

    public function counsellingStore(Request $req)
    {
        $v = Validator::make($req->all(), [
            'program_id' => 'required|exists:programs,id',
            'session_year' => 'required|string',
            'entrance_roll_no' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'father_name' => 'required|string|max:255',
            'mother_name' => 'required|string|max:255',
            'gender' => 'required|in:Male,Female,Transgender',
            'social_category' => 'required|in:General,OBC,SC,ST,EWS',
            'admission_category' => 'required|in:General,OBC,SC,ST,EWS',
            'admission_type' => 'required|in:Regular,Private',
            'state_rank' => 'required|integer',
            'category_rank' => 'nullable|integer',
            'cut_off_mark' => 'nullable|numeric',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $id = DB::table('counselling_reports')->insertGetId(array_merge($req->all(), [
            'entry_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Record saved successfully.'], 201);
    }

    public function counsellingDestroy($id)
    {
        DB::table('counselling_reports')->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }
}
