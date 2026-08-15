<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_branches', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            // Was unique(ifsc_code) — real bank data legitimately has
            // multiple branches sharing an IFSC in rare cases, so the
            // uniqueness key was widened to (ifsc_code, branch_name, city).
            $table->string('ifsc_code', 15);
            $table->string('micr_code', 15)->nullable();
            $table->string('branch_name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('state');
            $table->string('phone')->nullable();
            $table->timestamps();

            $table->index('ifsc_code');
            $table->index(['bank_name', 'state']);
            $table->unique(['ifsc_code', 'branch_name', 'city'], 'bank_branches_ifsc_branch_city_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_branches');
    }
};
