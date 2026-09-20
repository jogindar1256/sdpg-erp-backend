<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const UNIQUE_NAME = 'fee_structure_unique';

    private const CATEGORY_ABBR = [
        'general' => 'GEN',
        'gen' => 'GEN',
        'obc' => 'OBC',
        'sc' => 'SC',
        'st' => 'ST',
        'ews' => 'EWS',
    ];

    private function constraintExists(string $name): bool
    {
        return (bool) DB::selectOne(
            'select 1 from pg_constraint where conname = ? and conrelid = ?::regclass',
            [$name, 'fee_structures']
        );
    }

    private function dropUniqueIfExists(): void
    {
        if ($this->constraintExists(self::UNIQUE_NAME)) {
            Schema::table('fee_structures', function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE_NAME);
            });
        }
    }

    /**
     * Normalizes one raw row (whichever of the prior schema states it's
     * actually in) into a flat list of [fee_head_id, gender, category,
     * amount] tuples.
     */
    private function extractTuples(object $row): array
    {
        $hasFeeHeadId = property_exists($row, 'fee_head_id');
        $hasOldGenderCol = property_exists($row, 'gender') && !empty($row->gender);
        $tuples = [];

        if ($hasFeeHeadId && $hasOldGenderCol) {
            // Prior wrong state: one row per fee_head x gender x category,
            // gender/category as real columns.
            $tuples[] = [
                (int) $row->fee_head_id,
                strtolower($row->gender),
                strtolower((string) ($row->category ?? '')),
                (float) ($row->total_amount ?? 0),
            ];
        } elseif ($hasFeeHeadId && !empty($row->amounts ?? null)) {
            // True original state: one row per fee_head with an `amounts`
            // JSON blob keyed "{gender}_{category}".
            foreach ((array) (json_decode($row->amounts, true) ?: []) as $key => $amount) {
                [$g, $c] = array_pad(explode('_', (string) $key, 2), 2, null);
                if (!$g || !$c)
                    continue;
                $tuples[] = [(int) $row->fee_head_id, strtolower($g), strtolower($c), (float) $amount];
            }
        } elseif (!$hasFeeHeadId && !empty($row->amount_json ?? null) && !empty($row->category ?? null)) {
            $decoded = is_string($row->amount_json) ? json_decode($row->amount_json, true) : $row->amount_json;
            foreach ((array) ($decoded ?: []) as $gender => $heads) {
                foreach ((array) $heads as $feeHeadId => $amount) {
                    $tuples[] = [(int) $feeHeadId, strtolower((string) $gender), strtolower((string) $row->category), (float) $amount];
                }
            }
        } elseif (!$hasFeeHeadId && !empty($row->amount_json ?? null)) {
            // Prior wrong state: one row per configuration, amount_json
            // keyed by fee_head_id -> {"gender_category": amount}.
            $decoded = is_string($row->amount_json) ? json_decode($row->amount_json, true) : $row->amount_json;
            foreach ((array) ($decoded ?: []) as $feeHeadId => $genderCatMap) {
                foreach ((array) $genderCatMap as $key => $amount) {
                    [$g, $c] = array_pad(explode('_', (string) $key, 2), 2, null);
                    if (!$g || !$c)
                        continue;
                    $tuples[] = [(int) $feeHeadId, strtolower($g), strtolower($c), (float) $amount];
                }
            }
        }

        return $tuples;
    }

    public function up(): void
    {
        if (!Schema::hasColumn('fee_structures', 'category')) {
            Schema::table('fee_structures', function (Blueprint $table) {
                $table->string('category', 20)->nullable()->after('admission_type');
            });
        }
        if (!Schema::hasColumn('fee_structures', 'amount_json')) {
            Schema::table('fee_structures', function (Blueprint $table) {
                $table->json('amount_json')->nullable()->after('admission_type');
            });
        }

        $rows = DB::table('fee_structures')->orderBy('id')->get();

        $configs = [];
        foreach ($rows as $row) {
            $key = implode('|', [
                $row->organization_id,
                $row->program_id,
                $row->semester_no,
                $row->academic_year,
                $row->admission_type,
                $row->sdpgc_student ? 1 : 0,
                $row->ddu_affiliated ? 1 : 0,
            ]);
            if (!isset($configs[$key])) {
                $configs[$key] = [
                    'base' => [
                        'organization_id' => $row->organization_id,
                        'program_id' => $row->program_id,
                        'semester_no' => $row->semester_no,
                        'academic_year' => $row->academic_year,
                        'admission_type' => $row->admission_type,
                        'sdpgc_student' => $row->sdpgc_student,
                        'ddu_affiliated' => $row->ddu_affiliated,
                        'term' => $row->term,
                        'in_favor_of' => $row->in_favor_of,
                        'late_fine_per_day' => $row->late_fine_per_day,
                        'due_date' => $row->due_date,
                        'is_active' => $row->is_active,
                        'created_at' => $row->created_at,
                    ],
                    'tuples' => [],
                    'row_ids' => [],
                ];
            }
            $configs[$key]['row_ids'][] = $row->id;
            foreach ($this->extractTuples($row) as $t) {
                [$feeHeadId, $gender, $category, $amount] = $t;
                if (!$feeHeadId || !$gender || !$category)
                    continue;
                $configs[$key]['tuples'][] = $t;
            }
        }

        if (Schema::hasColumn('fee_structures', 'total_amount')) {
            Schema::table('fee_structures', function (Blueprint $table) {
                $table->dropColumn('total_amount');
            });
        }

        $programShortNames = DB::table('programs')->pluck('short_name', 'id');

        foreach ($configs as $cfg) {
            $byCategory = [];
            foreach ($cfg['tuples'] as [$feeHeadId, $gender, $category, $amount]) {
                $byCategory[$category][$gender][(string) $feeHeadId] = $amount;
            }

            DB::table('fee_structures')->whereIn('id', $cfg['row_ids'])->delete();

            foreach ($byCategory as $category => $genderMap) {
                $feeRefId = $this->nextFeeRefId($programShortNames[$cfg['base']['program_id']] ?? 'FS', $category);
                DB::table('fee_structures')->insert(array_merge($cfg['base'], [
                    'category' => $category,
                    'amount_json' => json_encode($genderMap),
                    'fee_ref_id' => $feeRefId,
                    'updated_at' => now(),
                ]));
            }
        }

        if (Schema::hasColumn('fee_structures', 'fee_head_id')) {
            $fk = DB::selectOne(
                "select 1 from pg_constraint where conrelid = 'fee_structures'::regclass and confrelid = 'fee_heads'::regclass and contype = 'f'"
            );
            if ($fk) {
                Schema::table('fee_structures', function (Blueprint $table) {
                    $table->dropForeign(['fee_head_id']);
                });
            }
            Schema::table('fee_structures', function (Blueprint $table) {
                $table->dropColumn('fee_head_id');
            });
        }
        if (Schema::hasColumn('fee_structures', 'amounts')) {
            Schema::table('fee_structures', function (Blueprint $table) {
                $table->dropColumn('amounts');
            });
        }
        if (Schema::hasColumn('fee_structures', 'gender')) {
            Schema::table('fee_structures', function (Blueprint $table) {
                $table->dropColumn('gender');
            });
        }

        $this->dropUniqueIfExists();
        Schema::table('fee_structures', function (Blueprint $table) {
            $table->unique(
                ['organization_id', 'program_id', 'semester_no', 'academic_year', 'admission_type', 'category', 'sdpgc_student', 'ddu_affiliated'],
                self::UNIQUE_NAME
            );
        });
    }

    /** Course + category + serial(3), e.g. BAGEN001. No gender component — see class header. */
    private function nextFeeRefId(string $programShort, string $category): string
    {
        $course = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $programShort)) ?: 'FS';
        $catAbbr = self::CATEGORY_ABBR[$category] ?? strtoupper(substr($category, 0, 3));
        $prefix = $course . $catAbbr;
        $total = strlen($prefix) + 3;

        $max = DB::table('fee_structures')
            ->where('fee_ref_id', 'like', $prefix . '%')
            ->whereRaw('length(fee_ref_id) = ?', [$total])
            ->max(DB::raw('CAST(substring(fee_ref_id FROM ' . (strlen($prefix) + 1) . ') AS INTEGER)'));

        return $prefix . str_pad((string) (((int) $max) + 1), 3, '0', STR_PAD_LEFT);
    }

    public function down(): void
    {
        // Intentional no-op — see class header "HISTORY THAT MATTERS".
    }
};
