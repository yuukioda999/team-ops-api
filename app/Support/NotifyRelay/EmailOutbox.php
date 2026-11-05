<?php

namespace App\Support\NotifyRelay;

use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * メールの配信依頼をアウトボックスに記録する(ADR-0002)。
 *
 * 呼び出し側のトランザクションの中で記録し、コミットされた後に送信ジョブを積む。
 * 業務データの保存と配信依頼が、片方だけ残ることはない。
 */
final class EmailOutbox
{
    public function __construct(private readonly bool $demoMode) {}

    /**
     * @param  string  $kind  依頼の種類(例:login_link)。ログと集計に使う
     * @param  string  $dedupeKey  依頼ごとに一意な値。notify-relay の重複防止キーになる
     * @param  array<string, string>  $recipients  recipient_ref => メールアドレス
     */
    public function queue(
        string $kind,
        string $dedupeKey,
        string $subject,
        string $text,
        array $recipients,
        ?string $url = null,
        ?CarbonInterface $expiresAt = null,
        string $tenantRef = 'system',
    ): OutboxMessage {
        if ($recipients === []) {
            throw new InvalidArgumentException('At least one recipient is required.');
        }
        if ($this->demoMode) {
            foreach ($recipients as $email) {
                if (! str_ends_with(strtolower($email), '.invalid')) {
                    // 宛先はメッセージに含めない(ログに残るため)
                    throw new InvalidArgumentException('Demo mode accepts only .invalid recipients.');
                }
            }
        }

        $payload = array_filter([
            'dedupe_key' => $dedupeKey,
            'tenant_ref' => $tenantRef,
            'message' => array_filter(['subject' => $subject, 'text' => $text, 'url' => $url]),
            'recipients' => array_map(
                fn (string $ref, string $email) => ['recipient_ref' => $ref, 'email' => $email],
                array_map('strval', array_keys($recipients)),
                array_values($recipients),
            ),
            'expires_at' => $expiresAt?->toIso8601String(),
        ], fn ($value) => $value !== null);

        return DB::transaction(function () use ($kind, $dedupeKey, $payload): OutboxMessage {
            $existing = OutboxMessage::where('dedupe_key', $dedupeKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            $message = OutboxMessage::create([
                'kind' => $kind,
                'dedupe_key' => $dedupeKey,
                'payload' => $payload,
            ]);

            DeliverOutboxMessage::dispatch($message->id)->afterCommit();

            return $message;
        });
    }
}
