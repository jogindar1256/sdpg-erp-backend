<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');                        // Bachelor of Arts, Master of Science
            $table->string('short_name', 20);             // BA, MSc, B.Ed
            $table->string('code', 20)->unique();
            $table->enum('level', ['UG', 'PG', 'BEd', 'Diploma', 'Certificate']);
            $table->integer('duration_years');             // 3, 2, 1
            $table->integer('total_semesters');            // 6, 4, 2
            $table->enum('semester_type', ['semester', 'annual'])->default('semester');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
