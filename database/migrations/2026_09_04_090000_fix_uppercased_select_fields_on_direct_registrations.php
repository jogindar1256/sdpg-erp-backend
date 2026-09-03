<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data repair for a bug in applyDraftUpdate() (office "Modify"
 * edits on /college/registration/status): every field passed through a
 * blanket TextNormalizer::upperValue() call, including columns that are
 * populated from a fixed <select> on the registration forms rather than
 * free text — category, admission_category, religion, nationality,
 * domestic_state, caste_cert_state, is_divyang, id_proof_type,
 * course_group, stream.
 *
 * Any office edit that touched (or merely re-submitted) one of those
 * fields silently rewrote it uppercase — 'General' -> 'GENERAL',
 * 'Sikh' -> 'SIKH', 'Uttar Pradesh' -> 'UTTAR PRADESH', etc. That breaks
 * every <select> that later tries to show the saved value (none of its
 * <option>s match the now-uppercase string, so the field renders blank),
 * and any server/JS logic that string-compares against the option list
 * (e.g. the caste-certificate-required check, or nationality's "Other"
 * custom-text fallback).
 *
 * gender is excluded here — direct_registrations_gender_check already
 * rejects a bad-case value outright, so no row could have been silently
 * corrupted there.
 *
 * This migration maps every UPPERCASE(value) that matches a known option
 * back to that option's canonical Title Case. Values that don't match any
 * known option (free text, e.g. a custom-typed nationality/state) are left
 * untouched — only exact known-vocabulary matches are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'category'           => ['General', 'OBC', 'SC', 'ST', 'EWS'],
            'admission_category' => ['General', 'OBC', 'SC', 'ST', 'EWS'],
            'religion'           => ['Hindu', 'Muslim', 'Christian', 'Sikh', 'Buddhist', 'Jain', 'Other'],
            'nationality'        => ['Indian', 'Other'],
            'domestic_state'     => self::ALL_STATES,
            'caste_cert_state'   => self::ALL_STATES,
            'is_divyang'         => ['No', 'Yes'],
            'id_proof_type'      => ['Aadhar Card', 'Voter ID', 'Driving License', 'PAN Card', 'Other'],
            'course_group'       => [
                'PCM (Physics, Chemistry, Maths)',
                'PCB (Physics, Chemistry, Biology)',
                'PCMB ()Physics, Chemistry, Maths, Biology)',
            ],
            'stream' => ['Science', 'Arts', 'Commerce', 'Mathematics', 'Biology', 'Home Science'],
        ];

        foreach ($columns as $column => $options) {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('direct_registrations', $column)) {
                continue;
            }
            foreach ($options as $canonical) {
                DB::table('direct_registrations')
                    ->whereRaw('upper(' . $column . ') = ?', [strtoupper($canonical)])
                    ->where($column, '!=', $canonical)
                    ->update([$column => $canonical]);
            }
        }
    }

    public function down(): void
    {
        // Not reversible — the original (correct) casing is what this
        // migration restores; there is nothing meaningful to roll back to.
    }

    private const ALL_STATES = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa',
        'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala',
        'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland',
        'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
        'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
        'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu',
        'Delhi', 'Jammu & Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
        'Other',
    ];
};
