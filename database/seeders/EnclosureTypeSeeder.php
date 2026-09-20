<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the fixed enclosure/document name list used by Master Settings'
 * Enclosure Master screen (/college/settings/admission/enclosure) and, via
 * ApplicationController::buildRequiredDocuments(), the student application
 * upload step. Runs on every `db:seed` (called from DatabaseSeeder) so a
 * fresh install always has this list — not something an admin has to type
 * in by hand.
 *
 * Idempotent: firstOrCreate() by `name` means running this again (e.g. a
 * redeploy) never duplicates rows. `key` — the stable machine identifier
 * used as document_type when a file is uploaded — is set ONLY on first
 * insert and is never touched again by this seeder, even if `name` here is
 * edited later. Only `sort_order` and `is_active` refresh on every run, so
 * reordering this array and reseeding is safe.
 */
class EnclosureTypeSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'High School Mark Sheet',
            'High School Certificate',
            '10+2 Mark Sheet',
            '10+2 Certificate',
            'TC - Transfer Certificate',
            'CC - Character Certificate',
            'Migration',
            'Graduation 1st Year Mark Sheet',
            'Graduation 2nd Year Mark Sheet',
            'Graduation 3rd Year Mark Sheet',
            'Graduation Certificate/Degree',
            'Caste Certificate',
            'Domicile Certificate',
            'Aadhar Card',
            'ABC ID',
            'DDURN Slip',
            'bank Passbook',
            'Confirm Allotment Letter',
            'Complete Application',
            'Score Card',
            'Paid Fee Receipt',
        ];

        foreach ($names as $i => $name) {
            $id = DB::table('enclosure_types')->where('name', $name)->value('id');
            if ($id) {
                DB::table('enclosure_types')->where('id', $id)->update([
                    'sort_order' => $i + 1,
                    'is_active'  => true,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('enclosure_types')->insert([
                    'name'       => $name,
                    'key'        => Str::slug($name, '_'),
                    'sort_order' => $i + 1,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
