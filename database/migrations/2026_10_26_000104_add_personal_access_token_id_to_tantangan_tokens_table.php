<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-03 (R-4 tinjauan): tantangan token 6 digit terikat ke token pra-login yang memintanya, selain
 * user + perangkat. Aditif; tantangan lama (null) tidak lagi dapat diverifikasi dan hangus sendiri
 * dalam 5 menit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tantangan_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tantangan_tokens', function (Blueprint $table) {
            $table->dropIndex(['personal_access_token_id']);
            $table->dropColumn('personal_access_token_id');
        });
    }
};
