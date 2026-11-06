<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ログイン後に戻る先(AC-001-19、ADR-0010)。招待リンクのトークンを含むので暗号化して持つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_tokens', function (Blueprint $table) {
            $table->text('return_to')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('login_tokens', function (Blueprint $table) {
            $table->dropColumn('return_to');
        });
    }
};
