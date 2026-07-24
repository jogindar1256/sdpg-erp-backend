<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `universities` table from database/seeders/data/universities.csv,
 * which was generated from the office's "Universities List.xlsx" reference
 * file (list of state universities of India: name, state, district).
 */
class UniversitySeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/universities.csv');

        if (!file_exists($path)) {
            $this->command?->warn("UniversitySeeder: {$path} not found, skipping.");
            return;
        }

        if (DB::table('universities')->count() > 0) {
            $this->command?->info('UniversitySeeder: universities table already populated, skipping.');
            return;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle); // name,state,district
        $now = now();
        $batch = [];
        $total = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3 || trim((string) $row[0]) === '') {
                continue;
            }

            $batch[] = [
                'name'       => trim($row[0]),
                'state'      => trim($row[1]) !== '' ? trim($row[1]) : null,
                'district'   => trim($row[2]) !== '' ? trim($row[2]) : null,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                DB::table('universities')->insertOrIgnore($batch);
                $total += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('universities')->insertOrIgnore($batch);
            $total += count($batch);
        }

        fclose($handle);

        $this->command?->info("UniversitySeeder: seeded {$total} universities.");
    }
}
