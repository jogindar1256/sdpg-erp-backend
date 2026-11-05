<?php

namespace App\Support;

/**
 * Turns one raw row of the "Academic Staff Data" sheet (teacher proforma used
 * for ID cards) into a clean academic_staff record. Pure functions, no DB —
 * the `staff:import` command does the writing. Anything that cannot be
 * trusted (bad Aadhaar / PAN / e-mail …) is dropped to null and reported as a
 * warning so it can be corrected on the Academic Staff page instead of being
 * silently stored wrong.
 */
class StaffImporter
{
    private const MONTHS = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];

    /** Collapse runs of spaces, trim; '', '-', '------' → null. */
    public static function text(?string $v): ?string
    {
        $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
        return ($v === '' || preg_match('/^[-–—.\s]+$/u', $v)) ? null : $v;
    }

    public static function digits(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    /** ISO (2001-12-05 …) or "21-JUL.-1967" / "22-Sep.-1992" → Y-m-d, else null. */
    public static function date(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        }
        if (preg_match('/^(\d{1,2})[-\/ ]+([A-Za-z]{3,})\.?[-\/ ]+(\d{4})$/', str_replace('.', '', $v), $m)) {
            $mon = self::MONTHS[strtoupper(substr($m[2], 0, 3))] ?? null;
            if ($mon && checkdate($mon, (int) $m[1], (int) $m[3])) return sprintf('%04d-%02d-%02d', $m[3], $mon, $m[1]);
        }
        return null;
    }

    /**
     * Most common spelling of each case-insensitive value, so
     * "Assistant professor" and "Assistant Professor" end up identical.
     *
     * @param  string[] $values
     * @return array<string,string> lower-case => canonical
     */
    public static function canonicalCase(array $values): array
    {
        $count = [];
        foreach ($values as $v) {
            $v = self::text($v);
            if ($v === null) continue;
            $count[mb_strtolower($v)][$v] = ($count[mb_strtolower($v)][$v] ?? 0) + 1;
        }
        $out = [];
        foreach ($count as $lower => $spellings) {
            arsort($spellings);
            // On a tie prefer the one with a lower-case letter (i.e. not shouting).
            $best = array_key_first($spellings);
            foreach ($spellings as $s => $n) {
                if ($n === reset($spellings) && $s !== mb_strtoupper($s)) { $best = $s; break; }
            }
            $out[$lower] = $best;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row   raw row keyed by the sheet's headers
     * @param array<string,string> $desig canonicalCase() of Designation
     * @param array<string,string> $dept  canonicalCase() of Department
     * @return array{data: array<string,mixed>, warnings: string[]}
     */
    public static function normalise(array $row, array $desig = [], array $dept = []): array
    {
        $w = [];
        $g = fn (string $k) => $row[$k] ?? null;

        $empId = self::digits($g('hHRMS ID'));
        $name = self::text($g('Name'));
        $label = ($name ?? '?') . " ({$empId})";

        // ── department / HOD ────────────────────────────────────────────
        $department = self::text($g('Department'));
        $remarks = null;
        if ($department && preg_match('/^HOD\s*-\s*(.+)$/i', $department, $m)) {
            $remarks = "HOD ('{$department}' in source sheet)";
            $department = trim($m[1]);
            $w[] = "$label: department '{$g('Department')}' → '{$department}', HOD kept in remarks";
        }
        $department = $department ? ($dept[mb_strtolower($department)] ?? $department) : null;

        $designation = self::text($g('Designation'));
        $designation = $designation ? ($desig[mb_strtolower($designation)] ?? $designation) : null;

        $dd = strtoupper((string) self::text($g('Departmental Designation')));
        $deptDesignation = $dd === 'INCHARGE' ? 'Incharge' : ($dd === 'NON' ? 'Non-Incharge' : self::text($g('Departmental Designation')));

        // ── dates ───────────────────────────────────────────────────────
        $dob = self::date($g('DOB'));
        $joining = self::date($g('Joining'));
        if ($dob === null) $w[] = "$label: DOB '{$g('DOB')}' not understood";
        if ($joining === null) $w[] = "$label: Joining '{$g('Joining')}' not understood";
        if ($dob && $joining && $joining <= $dob) $w[] = "$label: joining date is not after DOB — check both";

        // ── ids ─────────────────────────────────────────────────────────
        $aadhar = self::digits($g('Aadhar No.'));
        if ($aadhar !== '' && !preg_match('/^[2-9]\d{11}$/', $aadhar)) {
            $w[] = "$label: Aadhaar '{$g('Aadhar No.')}' is not a valid 12-digit number — left empty";
            $aadhar = '';
        }
        $pan = strtoupper(preg_replace('/\s+/', '', (string) $g('PAN NO.')));
        if ($pan !== '' && !preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $pan)) {
            $w[] = "$label: PAN '{$g('PAN NO.')}' invalid — left empty";
            $pan = '';
        }

        // PRAN column holds either "OPS" (old pension scheme) or a 12-digit PRAN (NPS).
        $pranRaw = strtoupper(trim((string) $g('PRAN NO.')));
        $pension = null; $pran = null;
        if ($pranRaw === 'OPS') {
            $pension = 'OPS';
        } elseif (preg_match('/^\d{12}$/', $pranRaw)) {
            $pension = 'NPS'; $pran = $pranRaw;
        } elseif ($pranRaw !== '') {
            $w[] = "$label: PRAN '{$pranRaw}' not understood — pension scheme left empty";
        }

        // ── contact ─────────────────────────────────────────────────────
        $mobile = self::digits($g('Mobile No.'));
        $alt = self::digits($g('Aternet Mobile No.'));
        if ($mobile !== '' && !preg_match('/^[6-9]\d{9}$/', $mobile)) { $w[] = "$label: mobile '{$mobile}' invalid — left empty"; $mobile = ''; }
        if ($alt !== '' && !preg_match('/^\d{10}$/', $alt)) { $w[] = "$label: alternate mobile '{$alt}' invalid — left empty"; $alt = ''; }
        $email = strtolower((string) self::text($g('E-mail')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $w[] = "$label: e-mail '{$email}' invalid — left empty";
            $email = '';
        }
        $pin = self::digits($g('PIN Code'));
        if ($pin !== '' && strlen($pin) !== 6) { $w[] = "$label: PIN '{$pin}' invalid — left empty"; $pin = ''; }

        // ── bank ────────────────────────────────────────────────────────
        $acct = self::digits($g('Bank A/C N0'));
        if ($acct !== '' && !preg_match('/^\d{9,18}$/', $acct)) { $w[] = "$label: bank account '{$acct}' looks wrong — kept, please verify"; }
        $ifsc = strtoupper(trim((string) $g('IFSC')));
        if ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) { $w[] = "$label: IFSC '{$ifsc}' invalid — left empty"; $ifsc = ''; }

        // ── address ─────────────────────────────────────────────────────
        $state = self::text($g('State'));
        if ($state && preg_match('/^U\.?\s*P\.?$/i', $state)) $state = 'Uttar Pradesh';
        $district = self::text(rtrim((string) $g('District'), " .,"));
        $district = $district ? mb_strtoupper($district) : null;

        $blood = self::text($g('Blood Group'));

        return ['data' => [
            'employee_id' => $empId,
            'name' => $name,
            'father_name' => self::text($g('Father')),
            'spouse_name' => self::text($g('Spose Name')),
            'status' => self::text($g('Status')) ?? 'Continue',   // kept exactly as in the sheet (Continue / WP)
            'designation' => $designation,
            'department' => $department,
            'departmental_designation' => $deptDesignation,
            'dob' => $dob,
            'joining_date' => $joining,
            'aadhar_no' => $aadhar ?: null,
            'pan_no' => $pan ?: null,
            'pension_scheme' => $pension,
            'pran_no' => $pran,
            'bank_name' => self::text($g('Bank Name')),
            'bank_branch' => self::text($g('Branch')),
            'bank_account_no' => $acct ?: null,
            'bank_ifsc' => $ifsc ?: null,
            'perm_village' => self::text($g('Mohalla/Vill')),
            'perm_post' => self::text($g('Post')),
            'perm_police_station' => self::text($g('Police Station')),
            'perm_district' => $district,
            'perm_state' => $state,
            'perm_pincode' => $pin ?: null,
            'mobile' => $mobile ?: null,
            'alt_mobile' => $alt ?: null,
            'email' => $email ?: null,
            'blood_group' => $blood,
            'nationality' => 'Indian',
            'remarks' => $remarks,
        ], 'warnings' => $w];
    }
}
