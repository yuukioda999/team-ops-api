<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 選手の名簿と、保護者(メンバー)との紐づけ(003、ADR-0013)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->text('display_name')->comment('表示名(暗号化。ADR-0003)');
            $table->string('grade', 10)->comment('preschool / e1〜e6 / j1〜j3');
            $table->unsignedSmallInteger('number')->nullable()->comment('背番号 0〜999');
            $table->timestamps();

            $table->index(['team_id', 'grade']);
        });

        Schema::create('player_guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            // メンバーがチームを抜けたら、紐づけも外れる
            $table->foreignId('membership_id')->constrained('team_memberships')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['player_id', 'membership_id']);
            $table->index('membership_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_guardians');
        Schema::dropIfExists('players');
    }
};
