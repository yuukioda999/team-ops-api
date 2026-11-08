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
            $table->foreignUlid('demo_team_id')->nullable()->comment('デモユーザーなら属するデモチーム。チームを消すと一緒に消える')->constrained('teams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('demo_team_id'));
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('demo_expires_at'));
    }
};
