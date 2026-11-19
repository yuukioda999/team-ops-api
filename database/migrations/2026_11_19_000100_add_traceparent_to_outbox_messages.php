<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アウトボックスに、作ったときのトレースの文脈を残す。送り直しても同じトレースにつなぐため(AC-010-03、ADR-0026)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->string('traceparent', 55)->nullable()->after('give_up_at')->comment('W3C traceparent。個人情報を含まない');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropColumn('traceparent');
        });
    }
};
