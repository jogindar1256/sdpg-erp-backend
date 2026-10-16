<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Registration modes are now Regular / Single Paper / Single Paper 3rd.
// Rows saved under the retired "Private" and "Back Paper" modes are removed.
// Not reversible — the deleted amounts are not restored.
return new class extends Migration
{
    public function up(): void
    {
        $n = DB::table('registration_fees')->whereIn('registration_mode', ['Private', 'Back Paper'])->delete();
        echo "  registration_fees: deleted {$n} Private / Back Paper row(s).\n";
    }

    public function down(): void
    {
        // Deleted data is not restored.
    }
};
