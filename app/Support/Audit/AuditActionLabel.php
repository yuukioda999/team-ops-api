<?php

namespace App\Support\Audit;

/**
 * 監査ログの操作の種類の、画面に出す日本語(AC-004-12)。
 * Audit::record に新しい action を足したら、ここにも足す(テストで確かめる)。
 */
final class AuditActionLabel
{
    public const array LABELS = [
        'invitation.issued' => '招待リンクを発行',
        'invitation.revoked' => '招待リンクを無効化',
        'join_request.approved' => '参加申請を承認',
        'join_request.rejected' => '参加申請を却下',
        'player.created' => '選手を登録',
        'player.updated' => '選手の情報を編集',
        'player.deleted' => '選手を削除',
        'player.guardians_changed' => '選手と保護者の紐づけを変更',
        'player.import_checked' => '名簿のCSVを確認',
        'player.imported' => '名簿のCSVを取り込み',
        'player.viewed_list' => '名簿を閲覧',
        'player.viewed' => '選手の詳細を閲覧',
        'member.roles_changed' => 'メンバーの役割を変更',
        'member.removed' => 'メンバーを除名',
        'team.representative_transferred' => '代表を交代',
        'team.updated' => 'チーム名・競技を変更',
        'notice.published' => 'お知らせを送信',
        'notice.scheduled' => 'お知らせを予約',
        'notice.updated' => '予約中のお知らせを編集',
        'notice.cancelled' => 'お知らせの予約を取り消し',
    ];

    public static function of(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }
}
