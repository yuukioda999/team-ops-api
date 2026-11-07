<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 既読・配信状況・再通知(006、ADR-0019・0020)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notice_recipients', function (Blueprint $table) {
            $table->dateTime('read_at', 6)->nullable()->comment('初めてアプリで開いた日時(AC-006-01)');
            $table->string('delivery_status', 10)->default('pending')->comment('pending / delivered / failed / skipped');
            $table->string('delivery_error', 32)->nullable()->comment('notify-relay の error_code、または送らなかった理由');
            $table->dateTime('delivery_status_at', 6)->nullable()->comment('状態の基準時刻。これより古い結果は採らない(ADR-0019)');
            $table->index(['notice_id', 'read_at']);
            $table->index(['notice_id', 'delivery_status']);
        });

        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->string('delivery_id', 30)->nullable()->index()->comment('notify-relay が受け付けたときの ID(照合に使う)');
            $table->unsignedInteger('results_received')->default(0)->comment('webhook で受け取った結果の数');
            $table->dateTime('relay_completed_at', 6)->nullable()->comment('delivery.completed を受け取った日時');
            $table->dateTime('reconcile_stopped_at', 6)->nullable()->comment('照合をやめた日時(完了・7日経過)');
        });

        // 受け取った webhook のイベント。同じ event_id は2回処理しない(ADR-0019)
        Schema::create('relay_events', function (Blueprint $table) {
            $table->string('event_id', 30)->primary();
            $table->dateTime('received_at', 6)->index();
        });

        // 照合で見つけた食い違い(AC-006-09)
        Schema::create('delivery_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbox_message_id')->constrained()->cascadeOnDelete();
            $table->string('relay_state', 12);
            $table->unsignedInteger('relay_finished')->comment('notify-relay で最終状態の数');
            $table->unsignedInteger('relay_pending');
            $table->unsignedInteger('results_received');
            $table->dateTime('checked_at', 6);
        });

        Schema::create('notice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number')->comment('1〜3');
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sent_count');
            $table->unsignedInteger('excluded_count');
            $table->dateTime('created_at', 6);

            $table->unique(['notice_id', 'number']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('email_undeliverable_at', 6)->nullable()->comment('お知らせのメールがアドレスの問題で届かなかった日時(ADR-0020)');
            $table->dateTime('email_changed_at', 6)->nullable()->comment('最後にメールアドレスを変えた日時');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['email_undeliverable_at', 'email_changed_at']));
        Schema::dropIfExists('notice_reminders');
        Schema::dropIfExists('delivery_reconciliations');
        Schema::dropIfExists('relay_events');
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropIndex(['delivery_id']);
            $table->dropColumn(['delivery_id', 'results_received', 'relay_completed_at', 'reconcile_stopped_at']);
        });
        Schema::table('notice_recipients', function (Blueprint $table) {
            $table->dropIndex(['notice_id', 'read_at']);
            $table->dropIndex(['notice_id', 'delivery_status']);
            $table->dropColumn(['read_at', 'delivery_status', 'delivery_error', 'delivery_status_at']);
        });
    }
};
