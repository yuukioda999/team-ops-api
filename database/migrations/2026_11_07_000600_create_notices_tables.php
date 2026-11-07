<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * お知らせと、送信時点の宛先(005、ADR-0017)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('title')->comment('暗号化(ADR-0017)');
            $table->text('body')->comment('暗号化(ADR-0017)');
            $table->string('target_type', 10)->comment('all / grades / roles');
            $table->json('target_values')->comment('学年または役割の値の一覧。all なら空');
            $table->string('status', 10)->comment('scheduled / published / cancelled');
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'status', 'published_at']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('notice_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained()->cascadeOnDelete();
            // 除名されても行は残す(006 の母数、ADR-0017)。アカウントが消えたら消す
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['notice_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_recipients');
        Schema::dropIfExists('notices');
    }
};
