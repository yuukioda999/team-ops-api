<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 招待リンクと参加申請(002、ADR-0010)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique()->comment('照合用の SHA-256');
            $table->text('token')->comment('代表・役員が見せ直すための暗号化したトークン(ADR-0010)');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'revoked_at', 'expires_at']);
        });

        Schema::create('team_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('team_invitations')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('message')->nullable()->comment('ひとこと(暗号化)。最終状態で消す(AC-002-17)');
            $table->string('status', 20)->comment('pending / approved / rejected / cancelled');
            $table->unsignedBigInteger('pending_user_id')->nullable()
                ->comment('承認待ちの間だけ user_id を入れる。一意制約で「承認待ちは1人1件」を守る(AC-002-09)');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'pending_user_id']);
            $table->index(['invitation_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_join_requests');
        Schema::dropIfExists('team_invitations');
    }
};
