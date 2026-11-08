<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * デモモード(007、ADR-0021)。デモチームとデモユーザーの印と期限。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dateTime('demo_expires_at')->nullable()->index()->comment('デモチームなら削除する日時(作成から24時間)。通常のチームは null');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('demo_team_id', 26)->nullable()->index()->comment('デモユーザーなら属するデモチーム。チームと一緒に消す');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('demo_team_id'));
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('demo_expires_at'));
    }
};
