<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * 監査ログに記録する(ADR-0012)。業務処理と同じトランザクションの中で呼ぶ。
 * details には ID と区分だけを入れる。表示名・メールアドレス・ひとことなどの個人情報は入れない。
 */
final class Audit
{
    /** details の値として受け付けるのは、ID や区分を表す短い値だけ */
    private const int MAX_VALUE_LENGTH = 64;

    /**
     * @param  array<string, int|string|bool|list<string>|null>  $details
     */
    public static function record(Team $team, ?User $actor, string $action, ?Model $subject = null, array $details = []): AuditLog
    {
        foreach ($details as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_string($item) && mb_strlen($item) > self::MAX_VALUE_LENGTH) {
                    throw new InvalidArgumentException("Audit detail {$key} is too long; record IDs and categories only.");
                }
            }
        }

        // subject_id は数値の列。ULID のモデル(チームなど)を渡すと MySQL で落ちるので、ここで止める
        if ($subject !== null && ! is_int($subject->getKey())) {
            throw new InvalidArgumentException('The audit subject must have an integer key; the team itself is identified by team_id.');
        }

        return AuditLog::create([
            'team_id' => $team->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'details' => $details === [] ? null : $details,
        ]);
    }
}
