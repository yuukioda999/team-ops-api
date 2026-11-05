<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel の雛形の users を、パスワードなしのログイン(ADR-0008)と
 * 個人情報の暗号化(ADR-0003)に合わせた形に作り直す。
 * まだ実データがない段階のため、作り直しで対応する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->text('email')->comment('暗号化');
            $table->char('email_index', 64)->unique()->comment('照合用のHMAC');
            $table->text('display_name')->nullable()->comment('暗号化。初回ログイン直後はNULL');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
