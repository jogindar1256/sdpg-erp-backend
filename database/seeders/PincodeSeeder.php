<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `pincodes` table from database/seeders/data/pincodes.csv,
 * which was generated from the office's "Pin Code, Dist, State All
 * India.xlsx" reference file (~155k post-office-level PIN code rows).
 *
 * Reads and inserts in chunks so this never has to hold the whole file
 * in memory, and can comfortably seed 150k+ rows.
 */
class PincodeSeeder extends Seeder
{
    private const CHUNK_SIZE = 2000;

    public function run(): void
    {
        $path = database_path('seeders/data/pincodes.csv');

        if (!file_exists($path)) {
            $this->command?->warn("PincodeSeeder: {$path} not found, skipping.");
            return;
        }

        if (DB::table('pincodes')->count() > 0) {
            $this->command?->info('PincodeSeeder: pincodes table already populated, skipping.');
            return;
        }

        $handle = fopen($path, 'r');
        fgetcsv($handle); // header: pincode,post_office_name,district,state_name
        $now = now();
        $batch = [];
        $total = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 4 || trim((string) $row[0]) === '') {
                continue;
            }

            $batch[] = [
                'pincode'          => trim($row[0]),
                'post_office_name' => trim($row[1]),
                'district'         => trim($row[2]) !== '' ? trim($row[2]) : null,
                'state_name'       => trim($row[3]) !== '' ? trim($row[3]) : null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            if (count($batch) >= self::CHUNK_SIZE) {
                DB::table('pincodes')->insertOrIgnore($batch);
                $total += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('pincodes')->insertOrIgnore($batch);
            $total += count($batch);
        }

        fclose($handle);

        $this->command?->info("PincodeSeeder: seeded {$total} pincode/post-office rows.");
    }
}
