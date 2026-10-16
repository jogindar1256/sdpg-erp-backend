<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// "Practical Subject Adjustments" (Fee If 01/02/03 Practical Sub.) removed
// from the fee structure entirely. Safe whether or not the table was ever
// created (its create migration is deleted alongside this one).
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fee_structure_practical_fees');
    }

    public function down(): void
    {
        // Feature removed — nothing to restore.
    }
};
