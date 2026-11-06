<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notify-relay への配信依頼を確実に届けるためのアウトボックス(ADR-0002)。
 * 業務データと同じトランザクションで記録し、ワーカーが送る。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 50);
            $table->string('dedupe_key', 191)->unique();
            $table->longText('payload')->comment('暗号化。宛先と本文を含む');
            $table->string('status', 20)->default('pending')->comment('pending / sent / failed');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 255)->nullable()->comment('個人情報を含めない');
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
