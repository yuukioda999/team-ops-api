<?php

namespace App\UseCases\Deliveries;

use App\Enums\DeliveryStatus;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Support\NotifyRelay\NoticeDeliveryKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * notify-relay の webhook を1件処理する(AC-006-08、ADR-0019)。
 * 同じ event_id は2回処理しない。お知らせのメールなら、宛先の行の配信状態を更新する。
 * 宛先の行が今いる送信(最初の送信か、何回目の再通知か)の結果だけを採り、前の送信の結果は捨てる。
 * 同じ送信の結果どうしは、結果の発生日時で前後を決める(到着順は保証されないため)。
 * アドレスが原因の失敗なら、そのユーザーに「メールが届かなかった日時」を入れる(ADR-0020)。
 */
final class ReceiveRelayEvent
{
    /**
     * @param  array{event_id: string, type: string, delivery_id: string, dedupe_key: string, results: list<array{recipient_ref: string, status: string, error_code: string|null, occurred_at: string}>, finished: int}  $event
     */
    public function __invoke(array $event): void
    {
        DB::transaction(function () use ($event): void {
            $now = now();
            if (DB::table('relay_events')->insertOrIgnore(['event_id' => $event['event_id'], 'received_at' => $now]) === 0) {
                return; // 処理済み
            }

            $outbox = OutboxMessage::where('dedupe_key', $event['dedupe_key'])->lockForUpdate()->first();
            if ($outbox !== null) {
                $outbox->delivery_id ??= $event['delivery_id'];
                if ($event['type'] === 'delivery.completed') {
                    // 結果がすべて届いているときだけ完了とする。足りなければ照合の対象に残す(AC-006-09)
                    if ($event['finished'] === $outbox->results_received) {
                        $outbox->relay_completed_at = $now;
                    }
                } else {
                    $outbox->results_received += count($event['results']);
                }
                $outbox->save();
            }

            $key = NoticeDeliveryKey::parse($event['dedupe_key']);
            if ($key === null || $event['results'] === []) {
                return; // お知らせ以外のメール(ログイン・招待など)の結果は使わない
            }
            $notice = Notice::where('team_id', $key['team_id'])->find($key['notice_id']);
            if ($notice === null) {
                return;
            }
            foreach ($event['results'] as $result) {
                $this->apply($notice, $key['round'], $outbox?->created_at, $result);
            }
        });
    }

    /**
     * @param  array{recipient_ref: string, status: string, error_code: string|null, occurred_at: string}  $result
     */
    private function apply(Notice $notice, int $round, ?Carbon $addressTakenAt, array $result): void
    {
        $userId = NoticeDeliveryKey::userId($result['recipient_ref']);
        if ($userId === null) {
            return;
        }
        $occurredAt = Carbon::parse($result['occurred_at'])->utc();
        $status = DeliveryStatus::from($result['status']);
        $updated = NoticeRecipient::where('notice_id', $notice->id)
            ->where('user_id', $userId)
            ->where('delivery_round', $round)
            ->where(fn ($q) => $q->whereNull('delivery_status_at')->orWhere('delivery_status_at', '<=', $occurredAt))
            ->update([
                'delivery_status' => $status,
                'delivery_error' => $status === DeliveryStatus::Delivered ? null : $result['error_code'],
                'delivery_status_at' => $occurredAt,
                'updated_at' => now(),
            ]);

        // 採った結果で、アドレスがその依頼を作った後に変わっていなければ印を付ける。
        // 送信が遅れて、変更前のアドレス宛ての失敗が変更後に届いても、新しいアドレスを止めない(ADR-0020)
        if ($updated > 0 && $addressTakenAt !== null && $status === DeliveryStatus::Failed && $result['error_code'] === 'invalid_address') {
            User::whereKey($userId)
                ->whereNull('email_undeliverable_at')
                ->where(fn ($q) => $q->whereNull('email_changed_at')->orWhere('email_changed_at', '<', $addressTakenAt))
                ->update(['email_undeliverable_at' => $occurredAt]);
        }
    }
}
