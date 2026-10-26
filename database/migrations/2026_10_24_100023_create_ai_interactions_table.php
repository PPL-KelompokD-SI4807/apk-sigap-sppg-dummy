<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->nullable()->constrained('sppgs')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('jenis_input', 20);
            $table->string('tool_name', 100)->nullable();
            $table->json('argumen')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('latensi_ms')->nullable();
            $table->string('hasil', 20)->index();
            $table->text('error')->nullable();
            $table->index(['sppg_id', 'created_at']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
    }
};
