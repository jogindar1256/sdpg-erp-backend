<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pincodes', function (Blueprint $table) {
            $table->id();
            $table->string('pincode', 10);
            $table->string('post_office_name');
            $table->string('district')->nullable();
            $table->string('state_name')->nullable();
            $table->timestamps();

            // A single pincode legitimately maps to many post offices
            // (branch offices/sub offices under one PIN), so no unique
            // constraint on pincode alone.
            $table->index('pincode');
            $table->index(['district', 'state_name']);
            $table->index('state_name');
            $table->unique(['pincode', 'post_office_name'], 'pincodes_pincode_post_office_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pincodes');
    }
};
