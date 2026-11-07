<?php

namespace App\Support\NotifyRelay;

/**
 * お知らせのメールの重複防止キー(ADR-0018・0020)。
 * 送信:team:{チームID}:notice:{お知らせID}:part:{分割番号}
 * 再通知:team:{チームID}:notice:{お知らせID}:reminder:{回数}:part:{分割番号}
 */
final class NoticeDeliveryKey
{
    private const string PATTERN = '/^team:([0-9A-Za-z]{1,26}):notice:([0-9]{1,18}):(?:reminder:([1-9]):)?part:[0-9]{1,6}$/';

    public static function publish(string $teamId, int $noticeId, int $part): string
    {
        return "team:{$teamId}:notice:{$noticeId}:part:{$part}";
    }

    public static function reminder(string $teamId, int $noticeId, int $number, int $part): string
    {
        return "team:{$teamId}:notice:{$noticeId}:reminder:{$number}:part:{$part}";
    }

    /**
     * @return array{team_id: string, notice_id: int, round: int}|null お知らせのキーでなければ null。round は 0 が最初の送信、1〜3 が再通知
     */
    public static function parse(string $key): ?array
    {
        if (preg_match(self::PATTERN, $key, $m) !== 1) {
            return null;
        }

        return ['team_id' => $m[1], 'notice_id' => (int) $m[2], 'round' => (int) ($m[3] ?? 0)];
    }

    /**
     * recipient_ref(user_{ID})からユーザーIDを取り出す。
     */
    public static function userId(string $recipientRef): ?int
    {
        return preg_match('/^user_([0-9]{1,18})$/', $recipientRef, $m) === 1 ? (int) $m[1] : null;
    }
}
