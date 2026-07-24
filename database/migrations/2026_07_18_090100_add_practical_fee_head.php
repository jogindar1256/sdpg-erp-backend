<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a "Practical Fee" fee_head (code PF, category exam) for every
 * organization. Back-paper papers whose subject_papers.paper_type is
 * 'Practical' price against this head (via fee_structures); every other
 * paper type prices against the existing 'Examination Fee' (EF) head.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Note: fee_heads.code has a *global* unique constraint (not scoped per
        // organization) in the original schema, so — matching how the seeder's
        // 'EF' / 'TF' etc. codes are handled — we only ever insert one 'PF' row
        // total, owned by the first organization.
        $orgId = DB::table('organizations')->orderBy('id')->value('id');
        if (!$orgId) {
            return;
        }

        $exists = DB::table('fee_heads')->where('code', 'PF')->exists();

        if (!$exists) {
            DB::table('fee_heads')->insert([
                'organization_id' => $orgId,
                'name'            => 'Practical Fee',
                'code'            => 'PF',
                'category'        => 'exam',
                'is_refundable'   => false,
                'is_mandatory'    => true,
                'description'     => 'Examination fee for Practical-type papers (back paper / regular).',
                'is_active'       => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('fee_heads')->where('code', 'PF')->delete();
    }
};
