<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 送信済み・失敗したアウトボックスの payload(宛先と本文)を消せるようにする(ADR-0003)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->longText('payload')->nullable()->comment('暗号化。宛先と本文を含む。送信済み・失敗で消す')->change();
            $table->timestamp('give_up_at')->nullable()->after('next_attempt_at')->comment('この時刻を過ぎたら送り直さない');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropColumn('give_up_at');
        });
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->longText('payload')->nullable(false)->comment('暗号化。宛先と本文を含む')->change();
        });
    }
};
