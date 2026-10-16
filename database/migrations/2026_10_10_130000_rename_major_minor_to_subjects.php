<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// U.G. "Major Subject -1/-2, Minor Subject -1" become three equal slots
// "Subject -1/-2/-3": major_subject_1 → subject_1, major_subject_2 →
// subject_2, minor_subject_1 → subject_3. The unused major_subject_3 is
// dropped only when no row holds a value.
return new class extends Migration
{
    private const RENAMES = [
        'major_subject_1' => 'subject_1',
        'major_subject_2' => 'subject_2',
        'minor_subject_1' => 'subject_3',
    ];

    private const PART_COLUMNS = ['part_1', 'part_2', 'part_3', 'part_4', 'part_5', 'part_6', 'part_7', 'part_8'];

    public function up(): void
    {
        if (Schema::hasTable('direct_registrations')) {
            foreach (self::RENAMES as $old => $new) {
                $this->renameColumn('direct_registrations', $old, $new);
            }
            if (Schema::hasColumn('direct_registrations', 'major_subject_3')
                && !DB::table('direct_registrations')->whereNotNull('major_subject_3')->exists()) {
                Schema::table('direct_registrations', function (Blueprint $t) {
                    $t->dropColumn('major_subject_3');
                });
            }
        }

        // Old keys inside the JSON parts: rename, and drop major_subject_3.
        // An already-present new key wins over the old one.
        $this->rewriteParts(self::RENAMES, ['major_subject_3']);
    }

    public function down(): void
    {
        if (Schema::hasTable('direct_registrations')) {
            foreach (self::RENAMES as $old => $new) {
                $this->renameColumn('direct_registrations', $new, $old);
            }
            if (!Schema::hasColumn('direct_registrations', 'major_subject_3')) {
                Schema::table('direct_registrations', function (Blueprint $t) {
                    $t->unsignedBigInteger('major_subject_3')->nullable();
                });
            }
        }

        // major_subject_3 values dropped from JSON in up() are not restored.
        $this->rewriteParts(array_flip(self::RENAMES), []);
    }

    private function renameColumn(string $table, string $from, string $to): void
    {
        if (Schema::hasColumn($table, $from) && !Schema::hasColumn($table, $to)) {
            DB::statement("ALTER TABLE {$table} RENAME COLUMN {$from} TO {$to}");
        }
    }

    /**
     * For every part_N column: move each $renames key to its new name and
     * remove $drop keys. Works on json or jsonb, written back in the same
     * type. Uses jsonb_exists*() (not the ? operator, which PDO treats as a
     * placeholder).
     */
    private function rewriteParts(array $renames, array $drop): void
    {
        if (!Schema::hasTable('student_applications')) {
            return;
        }

        foreach (self::PART_COLUMNS as $col) {
            $type = DB::selectOne(
                'SELECT data_type FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                ['student_applications', $col]
            )->data_type ?? null;
            if (!in_array($type, ['json', 'jsonb'], true)) {
                continue;
            }

            $j = "{$col}::jsonb";
            $remove = array_merge(array_keys($renames), $drop);
            $removeSql = implode(' ', array_map(fn($k) => "- '{$k}'", $remove));
            $moved = implode(' || ', array_map(
                fn($old, $new) => "(CASE WHEN jsonb_exists({$j}, '{$old}') THEN jsonb_build_object('{$new}', {$j} -> '{$old}') ELSE '{}'::jsonb END)",
                array_keys($renames),
                $renames
            ));
            $anyKey = "ARRAY['" . implode("','", $remove) . "']";

            DB::statement(
                "UPDATE student_applications
                 SET {$col} = ({$moved} || ({$j} {$removeSql}))::{$type}
                 WHERE {$col} IS NOT NULL
                   AND jsonb_typeof({$j}) = 'object'
                   AND jsonb_exists_any({$j}, {$anyKey})"
            );
        }
    }
};
