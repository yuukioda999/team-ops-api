<?php

namespace App\UseCases\Members;

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\NotifyRelay\EmailOutbox;

/**
 * 役割の変更・代表の交代を、本人にメールで知らせる(AC-004-03・04、ADR-0015)。
 * 文面にはチーム名と新しい役割だけを入れる。重複防止キーには監査ログの ID を使う。
 */
final class MemberNotifier
{
    public function __construct(private readonly EmailOutbox $outbox) {}

    public function rolesChanged(Team $team, TeamMembership $member, AuditLog $log): void
    {
        $this->send($team, $member, $log, 'member_roles_changed', '【チーム運営】役割が変更されました', "「{$team->name}」でのあなたの役割が変更されました。");
    }

    public function representativeTransferred(Team $team, TeamMembership $member, AuditLog $log): void
    {
        $this->send($team, $member, $log, 'representative_transferred', '【チーム運営】代表が交代しました', "「{$team->name}」の代表が交代しました。");
    }

    private function send(Team $team, TeamMembership $member, AuditLog $log, string $kind, string $subject, string $intro): void
    {
        $roles = implode('・', array_map(fn (TeamRole $r) => $r->label(), $member->roleList()));

        $this->outbox->queue(
            kind: $kind,
            dedupeKey: "team:{$team->id}:audit:{$log->id}:member:{$member->id}",
            subject: $subject,
            text: "{$intro}あなたの役割は「{$roles}」になりました。次のリンクから、チームの画面を開けます。",
            recipients: ['user_'.$member->user_id => User::findOrFail($member->user_id)->email],
            url: config('team_ops.web_url')."/teams/{$team->id}",
            tenantRef: "team:{$team->id}",
        );
    }
}
