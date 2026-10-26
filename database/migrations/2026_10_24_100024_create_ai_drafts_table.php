<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_drafts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ai_interaction_id')->nullable()->constrained('ai_interactions')->nullOnDelete();
            $table->string('module', 50);
            $table->string('action', 20);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('payload');
            $table->json('confidence')->nullable();
            $table->string('status', 20)->default('pending');
            $table->dateTime('expires_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->index(['sppg_id', 'user_id', 'status']);
            $table->index('expires_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_drafts');
    }
};
