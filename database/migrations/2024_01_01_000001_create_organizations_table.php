<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name', 50)->nullable();
            $table->string('code', 20)->unique();
            $table->string('type')->default('college'); // college, university
            $table->string('affiliation_no')->nullable();
            $table->string('address');
            $table->string('city');
            $table->string('district');
            $table->string('state');
            $table->string('pin_code', 10);
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('principal_name')->nullable();
            $table->string('university_name')->nullable();
            $table->string('university_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
