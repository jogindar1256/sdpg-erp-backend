<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicStaff;
use App\Models\AcademicStaffDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Academic Staff (college/organization/staff).
 * Photo + signature live on the public disk (ID cards need them); scanned
 * documents (Aadhaar, PAN, passbook …) stay on the private disk and are only
 * served through the authenticated download route.
 */
class StaffController extends Controller
{
    private const CATEGORIES = ['General', 'OBC', 'SC', 'ST', 'EWS'];
    private const BLOOD = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    private const MESSAGES = [
        'aadhar_no.regex' => 'Aadhaar must be 12 digits (cannot start with 0 or 1).',
        'pan_no.regex'    => 'PAN must look like ABCDE1234F.',
        'pran_no.regex'   => 'PRAN must be 12 digits.',
        'mobile.regex'    => 'Mobile must be a 10-digit number starting 6–9.',
        'bank_account_no.regex' => 'Account number must be 9–18 digits.',
        'bank_ifsc.regex' => 'IFSC must look like ABCD0123456.',
        'status_reason.required' => 'Reason is required when the status is not Continue / WP.',
    ];

    private function orgId(Request $r): int
    {
        return (int) $r->user()->organization_id;
    }

    private function find(Request $r, $id): AcademicStaff
    {
        return AcademicStaff::where('organization_id', $this->orgId($r))->findOrFail($id);
    }

    private function mediaUrl(?string $path): ?string
    {
        return $path ? rtrim(config('app.url'), '/') . Storage::url($path) : null;
    }

    private function present(AcademicStaff $s, bool $full = false): array
    {
        $row = $s->toArray();
        $row['is_active'] = $s->is_active;
        $row['photo_url'] = $this->mediaUrl($s->photo_path);
        $row['signature_url'] = $this->mediaUrl($s->signature_path);
        unset($row['photo_path'], $row['signature_path']);
        if (!$full) {
            // The list never carries ID numbers / bank details.
            foreach (['aadhar_no', 'pan_no', 'pran_no', 'bank_account_no', 'bank_ifsc'] as $k) unset($row[$k]);
        } else {
            $row['documents'] = $s->documents()->get(['id', 'doc_type', 'original_name', 'mime', 'size', 'created_at']);
        }
        return $row;
    }

    /** GET organization/staff */
    public function index(Request $r): JsonResponse
    {
        $q = AcademicStaff::where('organization_id', $this->orgId($r));

        if ($term = trim((string) $r->input('search', ''))) {
            $like = '%' . str_replace(['%', '_'], '', $term) . '%';
            $by = $r->input('search_by', 'all');
            $cols = ['employee_id', 'name', 'designation', 'department'];
            $q->where(function ($w) use ($by, $like, $cols) {
                foreach (in_array($by, $cols, true) ? [$by] : array_merge($cols, ['mobile', 'email']) as $c) {
                    $w->orWhere($c, 'ilike', $like);
                }
            });
        }
        $q->when($r->filled('department'), fn ($x) => $x->where('department', $r->department))
          ->when($r->filled('designation'), fn ($x) => $x->where('designation', $r->designation))
          ->when($r->filled('status'), fn ($x) => $x->where('status', $r->status));

        $page = $q->orderBy('department')->orderBy('name')->paginate(min(100, max(5, (int) $r->input('per_page', 25))));
        $page->getCollection()->transform(fn ($s) => $this->present($s));

        return response()->json($page);
    }

    /** GET organization/staff/options — filter / suggestion lists. */
    public function options(Request $r): JsonResponse
    {
        $base = fn () => AcademicStaff::where('organization_id', $this->orgId($r));
        return response()->json([
            'departments'  => $base()->distinct()->orderBy('department')->pluck('department'),
            'designations' => $base()->distinct()->orderBy('designation')->pluck('designation'),
            'statuses'     => AcademicStaff::STATUSES,
            'categories'   => self::CATEGORIES,
            'blood_groups' => self::BLOOD,
            'doc_types'    => AcademicStaff::DOC_TYPES,
        ]);
    }

    /** GET organization/staff/lookup?employee_id= — "Not Found, Create New". */
    public function lookup(Request $r): JsonResponse
    {
        $id = trim((string) $r->input('employee_id', ''));
        $s = $id === '' ? null : AcademicStaff::where('organization_id', $this->orgId($r))->where('employee_id', $id)->first();
        return response()->json(['found' => (bool) $s, 'id' => $s?->id, 'name' => $s?->name]);
    }

    public function show(Request $r, $id): JsonResponse
    {
        return response()->json($this->present($this->find($r, $id), true));
    }

    private function rules(Request $r, ?AcademicStaff $existing = null): array
    {
        $org = $this->orgId($r);
        $unique = fn (string $col) => Rule::unique('academic_staff', $col)
            ->where('organization_id', $org)->whereNull('deleted_at')->ignore($existing?->id);

        return [
            'employee_id'   => ['required', 'string', 'max:30', $unique('employee_id')],
            'name'          => 'required|string|max:150',
            'father_name'   => 'required|string|max:150',
            'spouse_name'   => 'nullable|string|max:150',
            'category'      => ['nullable', Rule::in(self::CATEGORIES)],
            'nationality'   => 'nullable|string|max:30',
            'domestic_state' => 'nullable|string|max:60',
            'blood_group'   => ['nullable', Rule::in(self::BLOOD)],
            'dob'           => 'nullable|date|before:today',
            'joining_date'  => 'nullable|date|after_or_equal:dob',
            'retirement_date' => 'nullable|date|after:joining_date',
            'designation'   => 'required|string|max:100',
            'department'    => 'required|string|max:100',
            'departmental_designation' => 'nullable|string|max:60',
            'aadhar_no'     => ['nullable', 'regex:/^[2-9]\d{11}$/', $unique('aadhar_no')],
            'pan_no'        => ['nullable', 'regex:/^[A-Z]{5}\d{4}[A-Z]$/'],
            'pension_scheme' => ['nullable', Rule::in(['NPS', 'OPS'])],
            'pran_no'       => ['nullable', 'regex:/^\d{12}$/'],
            'mobile'        => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'alt_mobile'    => ['nullable', 'regex:/^\d{10}$/'],
            'email'         => 'nullable|email|max:150',
            'bank_name'     => 'nullable|string|max:100',
            'bank_branch'   => 'nullable|string|max:100',
            'bank_account_no' => ['nullable', 'regex:/^\d{9,18}$/'],
            'bank_ifsc'     => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'status'        => ['required', Rule::in(AcademicStaff::STATUSES)],
            'status_reason' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => !in_array($r->input('status'), AcademicStaff::ACTIVE_STATUSES, true))],
            'status_ref_no' => 'nullable|string|max:60',
            'perm_village'  => 'nullable|string|max:200',
            'perm_post'     => 'nullable|string|max:100',
            'perm_police_station' => 'nullable|string|max:100',
            'perm_district' => 'nullable|string|max:100',
            'perm_state'    => 'nullable|string|max:60',
            'perm_pincode'  => ['nullable', 'regex:/^\d{6}$/'],
            'local_address' => 'nullable|string|max:500',
            'remarks'       => 'nullable|string|max:1000',
        ];
    }

    /** Normalise before validating: digits-only IDs, upper-case codes, trimmed text. */
    private function clean(Request $r): void
    {
        $in = $r->all();
        foreach (['aadhar_no', 'pran_no', 'mobile', 'alt_mobile', 'bank_account_no', 'perm_pincode'] as $k) {
            if (isset($in[$k])) $in[$k] = preg_replace('/\D/', '', (string) $in[$k]);
        }
        foreach (['pan_no', 'bank_ifsc'] as $k) {
            if (isset($in[$k])) $in[$k] = strtoupper(trim((string) $in[$k]));
        }
        if (isset($in['email'])) $in['email'] = strtolower(trim((string) $in['email']));
        if (isset($in['employee_id'])) $in['employee_id'] = trim((string) $in['employee_id']);
        if (($in['pension_scheme'] ?? null) !== 'NPS') $in['pran_no'] = null;
        // Empty strings become null (ConvertEmptyStringsToNull ran before the merge above).
        foreach ($in as $k => $v) if ($v === '') $in[$k] = null;
        $r->merge($in);
    }

    public function store(Request $r): JsonResponse
    {
        $this->clean($r);
        $data = $r->validate($this->rules($r), self::MESSAGES);
        $data['organization_id'] = $this->orgId($r);
        $data['nationality'] = $data['nationality'] ?? 'Indian';
        $s = AcademicStaff::create($data);

        return response()->json(['message' => 'Staff member saved.', 'staff' => $this->present($s, true)], 201);
    }

    public function update(Request $r, $id): JsonResponse
    {
        $s = $this->find($r, $id);
        $this->clean($r);
        $s->update($r->validate($this->rules($r, $s), self::MESSAGES));

        return response()->json(['message' => 'Staff member updated.', 'staff' => $this->present($s->fresh(), true)]);
    }

    public function destroy(Request $r, $id): JsonResponse
    {
        $this->find($r, $id)->delete();
        return response()->json(['message' => 'Staff member removed.']);
    }

    /** POST organization/staff/{id}/media — type=photo|signature */
    public function uploadMedia(Request $r, $id): JsonResponse
    {
        $s = $this->find($r, $id);
        $r->validate(['type' => 'required|in:photo,signature', 'file' => 'required|image|mimes:jpg,jpeg,png|max:2048']);

        $col = $r->type === 'photo' ? 'photo_path' : 'signature_path';
        if ($s->$col) Storage::disk('public')->delete($s->$col);
        $s->update([$col => $r->file('file')->store("staff/{$s->organization_id}/{$s->id}", 'public')]);

        return response()->json(['url' => $this->mediaUrl($s->$col)]);
    }

    /** POST organization/staff/{id}/documents — doc_type + file (pdf/jpg/png ≤3 MB), replaces the same type. */
    public function uploadDocument(Request $r, $id): JsonResponse
    {
        $s = $this->find($r, $id);
        $r->validate(['doc_type' => ['required', Rule::in(AcademicStaff::DOC_TYPES)], 'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:3072']);

        $old = AcademicStaffDocument::where('academic_staff_id', $s->id)->where('doc_type', $r->doc_type)->first();
        if ($old) { Storage::disk('local')->delete($old->path); $old->delete(); }

        $f = $r->file('file');
        $doc = AcademicStaffDocument::create([
            'academic_staff_id' => $s->id, 'doc_type' => $r->doc_type,
            'original_name' => $f->getClientOriginalName(), 'mime' => $f->getMimeType(), 'size' => $f->getSize(),
            'path' => $f->store("staff-documents/{$s->organization_id}/{$s->id}", 'local'),
        ]);

        return response()->json($doc->only('id', 'doc_type', 'original_name', 'mime', 'size', 'created_at'), 201);
    }

    public function downloadDocument(Request $r, $id, $docId)
    {
        $s = $this->find($r, $id);
        $doc = AcademicStaffDocument::where('academic_staff_id', $s->id)->findOrFail($docId);
        abort_unless(Storage::disk('local')->exists($doc->path), 404, 'File missing.');

        return Storage::disk('local')->download($doc->path, $doc->original_name);
    }

    public function deleteDocument(Request $r, $id, $docId): JsonResponse
    {
        $s = $this->find($r, $id);
        $doc = AcademicStaffDocument::where('academic_staff_id', $s->id)->findOrFail($docId);
        Storage::disk('local')->delete($doc->path);
        $doc->delete();

        return response()->json(['message' => 'Document removed.']);
    }
}
