<?php

use App\Models\Student;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('students') || !Schema::hasTable('student_applications')
            || !Schema::hasColumn('students', 'personal_info')
            || !Schema::hasColumn('students', 'confirmed_application_id')
        ) {
            return;
        }

        $keys = "'" . implode("','", Student::CERTIFICATE_FIELDS) . "'";

        DB::statement(<<<SQL
            UPDATE students s
               SET personal_info = src.extra || COALESCE(s.personal_info, '{}'::jsonb)
              FROM (
                    SELECT sa.id,
                           (SELECT jsonb_object_agg(e.key, e.value)
                              FROM jsonb_each(sa.part_1::jsonb) e
                             WHERE e.key IN ({$keys})
                               AND e.value NOT IN ('null'::jsonb, '""'::jsonb)) AS extra
                      FROM student_applications sa
                     WHERE sa.part_1 IS NOT NULL
                       AND jsonb_typeof(sa.part_1::jsonb) = 'object'
                   ) src
             WHERE src.id = s.confirmed_application_id
               AND src.extra IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // Nothing to undo: the copied values are the student's own data and
        // may have been amended since.
    }
};
