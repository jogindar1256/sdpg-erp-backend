<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');          // created, updated, deleted, login, logout
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->text('url')->nullable();
            $table->string('method', 10)->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['model_type', 'model_id']);
            $table->index(['organization_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
