<?php

namespace App\UseCases\Deliveries;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Support\NotifyRelay\RelayClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 照合(AC-006-09、ADR-0019)。notify-relay に渡してから1時間を過ぎても完了の通知が来ていない
 * お知らせのメールを notify-relay に問い合わせ、最終状態の数と webhook で受け取った結果の数を比べる。
 * 食い違えば記録してエラーログ(アラート)を出す。宛先ごとの状態はここでは直さない。
 * 7日を過ぎて notify-relay が依頼を消した(404)もの、件数が合って完了したものは、以後問い合わせない。
 */
final class ReconcileDeliveries
{
    private const int BATCH = 100;

    /** 受け取ったイベントの ID を残す日数。notify-relay は24時間まで送り直す */
    private const int KEEP_EVENT_DAYS = 8;

    public function __construct(private readonly RelayClient $relay) {}

    /**
     * @return array{checked: int, mismatched: int}
     */
    public function __invoke(): array
    {
        $now = now();
        $checked = 0;
        $mismatched = 0;
        $messages = OutboxMessage::whereIn('kind', ['notice', 'notice_reminder'])
            ->where('status', OutboxStatus::Sent)
            ->whereNotNull('delivery_id')
            ->whereNull('relay_completed_at')
            ->whereNull('reconcile_stopped_at')
            ->where('sent_at', '<=', $now->copy()->subHour())
            ->orderBy('sent_at')
            ->limit(self::BATCH)
            ->get();

        foreach ($messages as $message) {
            $status = $this->relay->getDelivery((string) $message->delivery_id);
            if ($status === null) {
                continue; // notify-relay に届かない。次の回にやり直す
            }
            $checked++;
            if ($status === 'not_found') {
                $message->forceFill(['reconcile_stopped_at' => $now])->save();

                continue;
            }
            $received = $message->fresh()->results_received ?? 0;
            if ($status['finished'] === $received) {
                if ($status['state'] === 'completed') {
                    $message->forceFill(['reconcile_stopped_at' => $now])->save();
                }

                continue;
            }
            $mismatched++;
            DB::table('delivery_reconciliations')->insert([
                'outbox_message_id' => $message->id,
                'relay_state' => $status['state'],
                'relay_finished' => $status['finished'],
                'relay_pending' => $status['pending'],
                'results_received' => $received,
                'checked_at' => $now,
            ]);
            Log::error('Delivery results from notify-relay do not match', [
                'outbox_message_id' => $message->id,
                'delivery_id' => $message->delivery_id,
                'relay_finished' => $status['finished'],
                'results_received' => $received,
            ]);
        }

        DB::table('relay_events')->where('received_at', '<', $now->copy()->subDays(self::KEEP_EVENT_DAYS))->delete();

        return ['checked' => $checked, 'mismatched' => $mismatched];
    }
}
