<?php

namespace App\Support;

use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * P.G. applicant who is a returning SDPGC student: when the new application
 * is created, carry the data (Parts 1-5) and the enclosures (Part 7 files)
 * over from the applicant's earlier UG application — everything except the
 * Part 6 subject / paper selection.
 *
 * Only when ALL of these hold (otherwise nothing is copied and the form
 * starts blank, exactly as before):
 *   - the new application is a 'regular' P.G. application with a registration;
 *   - the registration's UG roll no. matches one of OUR students;
 *   - the registration's DOB and Aadhaar match that student (the roll number
 *     alone is not proof of identity — it is typed into a public form);
 *   - that student's UG course is complete: an admission on a UG course that
 *     is 'passed_out' or reached the course's final semester.
 *
 * NOTE: the system holds no exam results, so "passed" is judged from the
 * admission record (passed_out / final semester reached), not from marks.
 */
class PreviousApplicationSeed
{
    /** Registration-owned Part 1 keys: the registration is the newer source, never overwritten by old data. */
    private const PART1_REG_OWNED = [
        'name_english', 'name_hindi', 'father_name_english', 'father_name_hindi',
        'mother_name_english', 'mother_name_hindi', 'gender', 'marital_status',
        'spouse_name_english', 'spouse_name_hindi', 'aadhar_no', 'date_of_birth',
        'social_category', 'religion', 'nationality', 'domestic_state',
        'abc_id', 'ddurn_id', 'family_id', 'exam_mode',
    ];

    /** Parts copied as-is. Part 6 (subject / papers) is deliberately absent. */
    private const COPY_PARTS = ['part_2', 'part_3', 'part_4', 'part_5', 'part_7'];

    /** @return bool true when something was copied */
    public static function seed(int $applicationId): bool
    {
        $app = DB::table('student_applications')->where('id', $applicationId)->whereNull('deleted_at')->first();
        if (!$app || $app->application_type !== 'regular' || empty($app->direct_registration_id)) {
            return false;
        }

        // Never overwrite anything already filled / uploaded.
        foreach (['part_1', 'part_2', 'part_3', 'part_4', 'part_5', 'part_6', 'part_7'] as $col) {
            if (!empty($app->{$col})) {
                return false;
            }
        }
        if (DB::table('student_application_documents')->where('application_id', $applicationId)->exists()) {
            return false;
        }

        $program = DB::table('programs')->where('id', $app->program_id)->first();
        if (!Part6Rules::isPg($program)) {
            return false;
        }

        $reg = DB::table('direct_registrations')->where('id', $app->direct_registration_id)->first();
        $roll = trim((string) ($reg->ug_roll_no ?? ''));
        if (!$reg || $roll === '') {
            return false;
        }

        $student = DB::table('students')
            ->whereNull('deleted_at')
            ->where(fn($q) => $q->where('university_roll_no', $roll)->orWhere('enrollment_no', $roll))
            ->first();
        $student = Student::flat($student);
        if (!$student) {
            return false;
        }

        if (!self::sameIdentity($reg, $student)) {
            return false;
        }
        if (!self::passedUg((int) $student->id)) {
            return false;
        }

        // The UG application that was filled in full: latest approved 'regular' one on a UG course.
        $source = DB::table('student_applications as sa')
            ->join('programs as p', 'p.id', 'sa.program_id')
            ->where('sa.student_id', $student->id)
            ->where('sa.application_type', 'regular')
            ->where('sa.status', 'approved')
            ->where('p.level', 'UG')
            ->whereNull('sa.deleted_at')
            ->orderByDesc('sa.id')
            ->select('sa.*')
            ->first();
        if (!$source) {
            return false;
        }

        $update = [];
        $progress = [];

        $part1 = self::decode($source->part_1 ?? null);
        foreach (self::PART1_REG_OWNED as $k) {
            unset($part1[$k]);
        }
        if ($part1) {
            $update['part_1'] = json_encode($part1);
            $progress['part1'] = true;
        }
        foreach (self::COPY_PARTS as $col) {
            if (!empty($source->{$col})) {
                $update[$col] = is_string($source->{$col}) ? $source->{$col} : json_encode($source->{$col});
                $progress['part' . substr($col, 5)] = true;
            }
        }

        $copiedDocs = self::copyDocuments((int) $source->id, $applicationId);
        if (!$update && !$copiedDocs) {
            return false;
        }

        // Part 7 is only "done" via its genuine-completeness flag (computed
        // from the uploaded documents) — don't claim it here.
        unset($progress['part7']);

        $update['form_progress'] = json_encode($progress);
        $update['updated_at'] = now();
        DB::table('student_applications')->where('id', $applicationId)->update($update);
        return true;
    }

    private static function sameIdentity(object $reg, object $student): bool
    {
        $regAadhaar = preg_replace('/\D/', '', (string) ($reg->aadhar_no ?? ''));
        $stuAadhaar = preg_replace('/\D/', '', (string) ($student->aadhar_no ?? ''));
        if ($regAadhaar === '' || $regAadhaar !== $stuAadhaar) {
            return false;
        }
        $regDob = substr((string) ($reg->dob ?? ''), 0, 10);
        $stuDob = substr((string) ($student->date_of_birth ?? ''), 0, 10);
        return $regDob !== '' && $regDob === $stuDob;
    }

    /** UG course finished: an admission that is passed_out, or in the course's final semester. */
    private static function passedUg(int $studentId): bool
    {
        return DB::table('admissions as a')
            ->join('programs as p', 'p.id', 'a.program_id')
            ->where('a.student_id', $studentId)
            ->where('p.level', 'UG')
            ->whereIn('a.status', ['active', 'passed_out'])
            ->where(function ($q) {
                $q->where('a.status', 'passed_out')
                    ->orWhereColumn('a.semester_no', '>=', 'p.total_semesters');
            })
            ->exists();
    }

    /** Duplicate each uploaded enclosure into the new application's own storage folder. */
    private static function copyDocuments(int $fromId, int $toId): int
    {
        $n = 0;
        $docs = DB::table('student_application_documents')->where('application_id', $fromId)->get();
        foreach ($docs as $doc) {
            // The recorded disk when there is one; older rows are probed.
            $disk = $doc->disk ?? null;
            foreach ($disk ? [] : ['supabase', 'public'] as $name) {
                try {
                    if (Storage::disk($name)->exists($doc->path)) {
                        $disk = $name;
                        break;
                    }
                } catch (\Throwable $e) {
                    // try the next disk
                }
            }
            if (!$disk) {
                continue;
            }
            $ext = pathinfo((string) $doc->path, PATHINFO_EXTENSION);
            $new = "student-applications/{$toId}/" . bin2hex(random_bytes(10)) . ($ext ? ".{$ext}" : '');
            try {
                Storage::disk($disk)->copy($doc->path, $new);
            } catch (\Throwable $e) {
                continue;
            }
            DB::table('student_application_documents')->updateOrInsert(
                ['application_id' => $toId, 'document_type' => $doc->document_type],
                [
                    'path' => $new,
                    'disk' => $disk,
                    'filename' => $doc->filename,
                    'status' => 'uploaded',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $n++;
        }
        return $n;
    }

    private static function decode($v): array
    {
        if (is_array($v)) {
            return $v;
        }
        return is_string($v) ? (json_decode($v, true) ?: []) : [];
    }
}
