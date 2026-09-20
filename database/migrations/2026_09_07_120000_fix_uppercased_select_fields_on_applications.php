<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Canonical option lists, copied verbatim from the frontend constants
     *  that own each field (Part1Personal.tsx, Part2Address.tsx,
     *  Part3Education.tsx, Part4TC.tsx, Part5Bank.tsx, src/lib/states.tsx). */
    private const FIELD_OPTIONS = [
        // Part 1 — Course Selection / Personal Details
        'gender'          => ['Male', 'Female', 'Other'],
        'marital_status'  => ['unmarried', 'married', 'divorced', 'widowed'],
        'social_category' => ['general', 'obc', 'sc', 'st', 'ews'],
        'religion'        => ['Hindu', 'Muslim', 'Christian', 'Sikh', 'Buddhist', 'Jain', 'Other'],
        'is_minority'     => ['no', 'yes'],
        'blood_group'     => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
        'id_proof_type'   => ['aadhar', 'pan', 'voter_id', 'dl', 'passport'],
        'is_divyang'      => ['No', 'Yes'],

        // Part 2 — Address & Communication (pres_/perm_ prefixes handled below)
        '_domestic_area'  => ['rural', 'urban', 'tribe', 'hard'],
        '_address_proof'  => ['aadhar', 'domicile', 'voter_id', 'dl', 'passport'],

        // Part 4 — TC & Migration Details
        'tc_condition'  => ['regular_not_applicable', 'have_original_tc', 'dont_have_tc'],
        'tc_behavior'   => ['Excellent', 'Good', 'Satisfactory', 'Unsatisfactory'],
        'mig_condition' => ['regular_not_applicable', 'have_original_migration', 'dont_have_migration'],
        'mig_reason'    => ['Passed Out', 'Cancelled', 'Personal Reason', 'Financial Reason', 'Other'],

        // Part 5 — Bank Details
        'account_type' => ['saving', 'cc', 'jandhan', 'other'],
    ];

    /** Part 3 education_records[] fields — course_name is level-scoped so
     *  it's handled separately; the rest share one canonical list each. */
    private const PART3_FIELD_OPTIONS = [
        'board_university' => ['U.P. Board', 'Bihar Intermediate Board', 'CBSE Board', 'Rajasthan Board', 'Other'],
        'exam_system'       => ['Annual Mode', 'Semester Mode', 'Other'],
        'number_system'     => ['Marks', 'CGPA', 'SGPA', 'Grade'],
        'result'            => ['Pass', 'Promoted', 'First Division', 'Second Division', 'Third Division', 'Distinction'],
        'group'             => ['Arts', 'Science', 'Commerce', 'Business'],
    ];

    private const COURSE_NAMES = [
        'High School', 'Intermediate',
        'U.G. 1st Yr.', 'U.G. 2nd Yr.', 'U.G. 3rd Yr.', 'U.G. 4th Yr.',
        'PG 1st Yr.', 'PG 2nd Yr.', 'Other',
    ];

    private const SUBJECTS = [
        // HIGH_SCHOOL_SUBJECTS
        'Hindi', 'English', 'Mathematics', 'Science', 'Social Science', 'Sanskrit', 'Drawing',
        'Computer Science', 'Information Technology',
        // ARTS_SUBJECTS (adds)
        'History', 'Political Science', 'Economics', 'Sociology', 'Geography', 'Psychology',
        'Philosophy', 'Home Science', 'Music',
        // SCIENCE_SUBJECTS (adds)
        'Physics', 'Chemistry', 'Botany', 'Zoology', 'Environmental Science', 'Physical Education',
        'Programming with Python',
        // COMMERCE_SUBJECTS (adds)
        'Accountancy', 'Business Studies', 'Business Mathematics', 'Income Tax', 'Auditing',
    ];

    private const ALL_STATES = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat',
        'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh',
        'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab',
        'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand',
        'West Bengal',
        'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu',
        'Delhi', 'Jammu & Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
    ];

    public function up(): void
    {
        DB::table('student_applications')
            ->select('id', 'part_1', 'part_2', 'part_3', 'part_4', 'part_5')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $update = [];

                    if ($fixed = $this->fixFlat($row->part_1, self::FIELD_OPTIONS)) {
                        $update['part_1'] = json_encode($fixed);
                    }
                    if ($fixed = $this->fixPart2($row->part_2)) {
                        $update['part_2'] = json_encode($fixed);
                    }
                    if ($fixed = $this->fixPart3($row->part_3)) {
                        $update['part_3'] = json_encode($fixed);
                    }
                    if ($fixed = $this->fixFlat($row->part_4, self::FIELD_OPTIONS)) {
                        $update['part_4'] = json_encode($fixed);
                    }
                    if ($fixed = $this->fixFlat($row->part_5, self::FIELD_OPTIONS)) {
                        $update['part_5'] = json_encode($fixed);
                    }

                    if ($update) {
                        $update['updated_at'] = now();
                        DB::table('student_applications')->where('id', $row->id)->update($update);
                    }
                }
            });
    }

    /** Case-insensitively match $value against $options; null if no match
     *  (including when $value isn't a plain string — defensive against any
     *  already-malformed row shape). */
    private function canonicalize(mixed $value, array $options): ?string
    {
        if (!is_string($value) || $value === '') return null;
        foreach ($options as $opt) {
            if (strcasecmp($value, $opt) === 0 && $value !== $opt) {
                return $opt;
            }
        }
        return null;
    }

    /** Flat (non-nested) part — returns the corrected array, or null if nothing changed. */
    private function fixFlat(?string $json, array $fieldOptions): ?array
    {
        if (!$json) return null;
        $data = json_decode($json, true);
        if (!is_array($data)) return null;

        $changed = false;
        foreach ($fieldOptions as $key => $options) {
            if (str_starts_with($key, '_')) continue; // Part 2 prefixed keys, handled in fixPart2()
            if (!array_key_exists($key, $data)) continue;
            $canon = $this->canonicalize($data[$key] ?? null, $options);
            if ($canon !== null) {
                $data[$key] = $canon;
                $changed = true;
            }
        }
        return $changed ? $data : null;
    }

    private function fixPart2(?string $json): ?array
    {
        if (!$json) return null;
        $data = json_decode($json, true);
        if (!is_array($data)) return null;

        $changed = false;
        foreach (['pres', 'perm'] as $prefix) {
            $checks = [
                "{$prefix}_domestic_area" => self::FIELD_OPTIONS['_domestic_area'],
                "{$prefix}_address_proof" => self::FIELD_OPTIONS['_address_proof'],
                "{$prefix}_state"         => self::ALL_STATES,
            ];
            foreach ($checks as $key => $options) {
                if (!array_key_exists($key, $data)) continue;
                $canon = $this->canonicalize($data[$key] ?? null, $options);
                if ($canon !== null) {
                    $data[$key] = $canon;
                    $changed = true;
                }
            }
        }
        return $changed ? $data : null;
    }

    private function fixPart3(?string $json): ?array
    {
        if (!$json) return null;
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['education_records']) || !is_array($data['education_records'])) {
            return null;
        }

        $changed = false;
        foreach ($data['education_records'] as &$rec) {
            if (!is_array($rec)) continue;

            if (isset($rec['course_name'])) {
                $canon = $this->canonicalize($rec['course_name'], self::COURSE_NAMES);
                if ($canon !== null) { $rec['course_name'] = $canon; $changed = true; }
            }
            foreach (self::PART3_FIELD_OPTIONS as $key => $options) {
                if (!isset($rec[$key])) continue;
                $canon = $this->canonicalize($rec[$key], $options);
                if ($canon !== null) { $rec[$key] = $canon; $changed = true; }
            }
            if (isset($rec['drop_subject'])) {
                $canon = $this->canonicalize($rec['drop_subject'], self::SUBJECTS);
                if ($canon !== null) { $rec['drop_subject'] = $canon; $changed = true; }
            }
            if (isset($rec['subjects']) && is_array($rec['subjects'])) {
                foreach ($rec['subjects'] as &$subj) {
                    if (!is_string($subj)) continue;
                    $canon = $this->canonicalize($subj, self::SUBJECTS);
                    if ($canon !== null) { $subj = $canon; $changed = true; }
                }
                unset($subj);
            }
        }
        unset($rec);

        return $changed ? $data : null;
    }

    public function down(): void
    {
        // Not reversible — see class doc comment.
    }
};
