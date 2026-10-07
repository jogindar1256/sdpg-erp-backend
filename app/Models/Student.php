<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use SoftDeletes;

    /**
     * Grouped (jsonb) columns and the fields each one holds.
     *
     * These fields used to be separate columns. They are still addressed by
     * their old flat names everywhere in the app:
     *   - Eloquent:  $student->first_name, Student::create(['bank_ifsc' => …])
     *     work through getAttribute()/setAttribute() below, and toArray()
     *     keeps emitting the flat keys so API responses did not change shape.
     *   - Query builder (DB::table('students')): read rows through
     *     Student::flat(), write through Student::groupedUpdate() /
     *     Student::pack(); in joins select 's.personal_info->first_name as first_name'.
     *
     * mobile, email, aadhar_no and abc_id stay as real columns (unique
     * indexes, login lookups). date_of_birth is a plain 'YYYY-MM-DD' string
     * inside personal_info — it is no longer cast to a Carbon date.
     */
    /**
     * Certificate / voter / passport particulars collected in Part 1 of the
     * application form. Same key names as student_applications.part_1, so
     * they copy across unchanged when the student row is created. Stored in
     * personal_info. All optional.
     */
    public const CERTIFICATE_FIELDS = [
        'admission_category', 'admission_category_cert_no',
        'domicile_issue_district', 'domicile_issue_date',
        'other_cert_name', 'other_cert_no', 'other_cert_state', 'other_cert_date',
        'cmo_cert_no', 'cmo_cert_date', 'cmo_cert_district', 'cmo_cert_state',
        'income_cert_date', 'income_cert_state', 'income_cert_district', 'income_cert_sub_district',
        'is_voter', 'has_epic', 'epic_no', 'voter_state', 'voter_constituency',
        'has_passport', 'passport_no', 'passport_state', 'passport_district',
    ];

    /** Of the above: dates, and Yes/No answers (never upper-cased). */
    public const CERTIFICATE_DATE_FIELDS = ['domicile_issue_date', 'other_cert_date', 'cmo_cert_date', 'income_cert_date'];
    public const CERTIFICATE_CHOICE_FIELDS = ['admission_category', 'is_voter', 'has_epic', 'has_passport'];

    public const GROUPS = [
        'personal_info' => [
            'first_name', 'middle_name', 'last_name',
            'gender', 'date_of_birth', 'category',
            'religion', 'nationality', 'alternate_mobile', 'whatsapp_no',
            ...self::CERTIFICATE_FIELDS,
        ],
        'address_info' => [
            'permanent_address', 'permanent_city', 'permanent_district', 'permanent_state', 'permanent_pin',
            'same_as_permanent',
            'correspondence_address', 'correspondence_city', 'correspondence_district',
            'correspondence_state', 'correspondence_pin',
        ],
        'last_exam_details' => [
            'last_exam_passed', 'last_exam_board', 'last_exam_roll_no', 'last_exam_year',
            'last_exam_percentage', 'last_exam_division',
        ],
        'tc_migration_info' => [
            'tc_no', 'tc_date', 'tc_issued_by', 'migration_no', 'migration_date',
        ],
        'bank_details' => [
            'bank_name', 'bank_branch', 'bank_ifsc', 'bank_account_no',
        ],
    ];

    protected $fillable = [
        'organization_id', 'user_id', 'enrollment_no', 'university_roll_no', 'student_uid',
        'aadhar_no', 'abc_id', 'mobile', 'email',
        'photo_path', 'signature_path', 'biometric_id',
        'status', 'is_blocked', 'block_reason',
        // Grouped columns, and their fields by flat name (routed into the
        // right group by setAttribute()).
        'personal_info', 'address_info', 'last_exam_details', 'tc_migration_info', 'bank_details',
        'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'category',
        'religion', 'nationality', 'alternate_mobile', 'whatsapp_no',
        'permanent_address', 'permanent_city', 'permanent_district', 'permanent_state', 'permanent_pin',
        'same_as_permanent', 'correspondence_address', 'correspondence_city',
        'correspondence_district', 'correspondence_state', 'correspondence_pin',
        'last_exam_passed', 'last_exam_board', 'last_exam_roll_no', 'last_exam_year',
        'last_exam_percentage', 'last_exam_division',
        'tc_no', 'tc_date', 'tc_issued_by', 'migration_no', 'migration_date',
        'bank_name', 'bank_branch', 'bank_ifsc', 'bank_account_no',
        ...self::CERTIFICATE_FIELDS,
    ];

    /** The CERTIFICATE_FIELDS present (non-empty) in a decoded part_1. */
    public static function certificateFieldsFrom(array $part1): array
    {
        $out = [];
        foreach (self::CERTIFICATE_FIELDS as $key) {
            $value = $part1[$key] ?? null;
            if ($value !== null && $value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** Laravel validation rules for CERTIFICATE_FIELDS (all nullable). */
    public static function certificateRules(): array
    {
        $rules = [];
        foreach (self::CERTIFICATE_FIELDS as $key) {
            $rules[$key] = 'nullable|string|max:100';
        }
        foreach (self::CERTIFICATE_DATE_FIELDS as $key) {
            $rules[$key] = 'nullable|date|before_or_equal:today';
        }
        foreach (['is_voter', 'has_epic', 'has_passport'] as $key) {
            $rules[$key] = 'nullable|in:Yes,No';
        }
        $rules['admission_category'] = 'nullable|in:Social Category,H.C.,Freedom Fighter,EWS';
        $rules['epic_no'] = 'nullable|string|max:20';
        $rules['passport_no'] = 'nullable|string|max:20';
        return $rules;
    }

    protected $casts = [
        'is_blocked' => 'boolean',
        'personal_info' => 'array',
        'address_info' => 'array',
        'last_exam_details' => 'array',
        'tc_migration_info' => 'array',
        'bank_details' => 'array',
    ];

    /** Which grouped column a flat field lives in, or null for a real column. */
    public static function groupOf(string $key): ?string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::GROUPS as $group => $fields) {
                foreach ($fields as $f) {
                    $map[$f] = $group;
                }
            }
        }
        return $map[$key] ?? null;
    }

    public function getAttribute($key)
    {
        if (is_string($key) && ($group = self::groupOf($key))) {
            $data = parent::getAttribute($group);
            return is_array($data) ? ($data[$key] ?? null) : null;
        }
        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        if (is_string($key) && ($group = self::groupOf($key))) {
            $data = parent::getAttribute($group);
            $data = is_array($data) ? $data : [];
            $data[$key] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
            return parent::setAttribute($group, $data);
        }
        return parent::setAttribute($key, $value);
    }

    /** Serialised output keeps the old flat keys next to the grouped objects. */
    public function toArray()
    {
        $out = parent::toArray();
        foreach (self::GROUPS as $group => $fields) {
            if (!array_key_exists($group, $out)) {
                continue; // group column was not selected
            }
            $data = is_array($out[$group]) ? $out[$group] : [];
            foreach ($fields as $f) {
                if (!array_key_exists($f, $out)) {
                    $out[$f] = $data[$f] ?? null;
                }
            }
        }
        return $out;
    }

    // ── Query-builder helpers (DB::table('students') rows) ─────────────────

    /**
     * Decode the grouped columns on a raw students row and expose every
     * grouped field as a flat property, so `$row->first_name` keeps working.
     * Mutates and returns the row; null-safe.
     */
    public static function flat(?object $row): ?object
    {
        if (!$row) {
            return null;
        }
        foreach (self::GROUPS as $group => $fields) {
            if (!property_exists($row, $group)) {
                continue;
            }
            $data = $row->$group;
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
            $data = is_array($data) ? $data : [];
            $row->$group = $data;
            foreach ($fields as $f) {
                if (!property_exists($row, $f)) {
                    $row->$f = $data[$f] ?? null;
                }
            }
        }
        return $row;
    }

    /**
     * Turn a flat attribute array into real column values: grouped fields are
     * folded into their jsonb column (merged over $existing, a raw row or
     * array of the current values), everything else passes through.
     */
    public static function pack(array $flat, $existing = null): array
    {
        $existing = is_object($existing) ? (array) $existing : ($existing ?? []);
        $out = [];
        $groups = [];
        foreach ($flat as $key => $value) {
            $group = self::groupOf($key);
            if (!$group) {
                $out[$key] = $value;
                continue;
            }
            if (!isset($groups[$group])) {
                $current = $existing[$group] ?? [];
                if (is_string($current)) {
                    $current = json_decode($current, true);
                }
                $groups[$group] = is_array($current) ? $current : [];
            }
            $groups[$group][$key] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        foreach ($groups as $group => $data) {
            $out[$group] = json_encode($data);
        }
        return $out;
    }

    /** DB::table('students')->where('id', $id)->update($flat), group-aware. */
    public static function groupedUpdate($id, array $flat): int
    {
        if (empty($flat)) {
            return 0;
        }
        $needsExisting = (bool) array_filter(array_keys($flat), fn($k) => self::groupOf($k));
        $existing = $needsExisting
            ? \Illuminate\Support\Facades\DB::table('students')->where('id', $id)->first(array_keys(self::GROUPS))
            : null;
        return \Illuminate\Support\Facades\DB::table('students')->where('id', $id)->update(self::pack($flat, $existing));
    }

    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(StudentApplication::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function currentAdmission(): HasOne
    {
        return $this->hasOne(Admission::class)->where('status', 'active')->latest();
    }

    public function feeReceipts(): HasMany
    {
        return $this->hasMany(FeeReceipt::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function semesterRegistrations(): HasMany
    {
        return $this->hasMany(SemesterRegistration::class);
    }

    public function examApplications(): HasMany
    {
        return $this->hasMany(ExamApplication::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(Amendment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }
}
