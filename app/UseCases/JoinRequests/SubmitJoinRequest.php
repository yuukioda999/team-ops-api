<?php

namespace App\UseCases\JoinRequests;

use App\Enums\JoinRequestStatus;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\TeamMemberRole;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\NotifyRelay\EmailOutbox;
use App\UseCases\Invitations\FindInvitation;
use Illuminate\Support\Facades\DB;

/**
 * 招待リンクから参加を申請する(AC-002-06・08・09・10・16)。
 */
final class SubmitJoinRequest
{
    /** 1つの招待リンクで、直近24時間に受け付ける申請の数(AC-002-10) */
    public const int MAX_PER_LINK_PER_DAY = 50;

    /** 同じ人が同じチームへ、直近24時間に申請できる回数(AC-002-18)。却下のたびの再申請で通知が続かないように */
    public const int MAX_PER_PERSON_PER_DAY = 3;

    public function __construct(
        private readonly EmailOutbox $outbox,
        private readonly FindInvitation $findInvitation,
    ) {}

    /**
     * @return array{JoinRequestOutcome, Team|null, TeamJoinRequest|null}
     */
    public function __invoke(User $user, string $plainToken, string $displayName, ?string $message): array
    {
        return DB::transaction(function () use ($user, $plainToken, $displayName, $message): array {
            // 同じリンクへの申請が同時に来ても、上限の数え方がずれないようリンクの行をロックする
            $invitation = ($this->findInvitation)($plainToken, lock: true);
            if ($invitation === null) {
                return [JoinRequestOutcome::InvalidLink, null, null];
            }
            $team = $invitation->team()->firstOrFail();

            if (TeamMembership::where('team_id', $team->id)->where('user_id', $user->id)->exists()) {
                return [JoinRequestOutcome::AlreadyMember, $team, null];
            }

            $pending = TeamJoinRequest::where('team_id', $team->id)->where('pending_user_id', $user->id)->first();
            if ($pending !== null) {
                return [JoinRequestOutcome::AlreadyPending, $team, $pending];
            }

            $mine = TeamJoinRequest::where('team_id', $team->id)->where('user_id', $user->id)->where('created_at', '>', now()->subDay())->count();
            if ($mine >= self::MAX_PER_PERSON_PER_DAY) {
                return [JoinRequestOutcome::TooManyFromPerson, $team, null];
            }

            $recent = TeamJoinRequest::where('invitation_id', $invitation->id)->where('created_at', '>', now()->subDay())->count();
            if ($recent >= self::MAX_PER_LINK_PER_DAY) {
                $this->notifyRepresentativeOfFlood($team, $invitation->id);

                return [JoinRequestOutcome::TooMany, $team, null];
            }

            // 申請画面の表示名は、アカウントの表示名(全チーム共通、判断5)
            $user->forceFill(['display_name' => $displayName])->save();

            $request = TeamJoinRequest::create([
                'team_id' => $team->id,
                'invitation_id' => $invitation->id,
                'user_id' => $user->id,
                'message' => $message,
                'status' => JoinRequestStatus::Pending,
                'pending_user_id' => $user->id,
            ]);

            $this->notifyReviewers($team, $request);

            return [JoinRequestOutcome::Submitted, $team, $request];
        });
    }

    /**
     * 代表・役員に、承認待ちの申請が来たことを知らせる(AC-002-06)。
     * メールには申請者の表示名もひとことも書かない。画面で確かめてもらう。
     */
    private function notifyReviewers(Team $team, TeamJoinRequest $request): void
    {
        $recipients = $this->membersWithRoles($team, [TeamRole::Representative, TeamRole::Officer]);
        if ($recipients === []) {
            return;
        }

        $this->outbox->queue(
            kind: 'join_request_submitted',
            dedupeKey: "team:{$team->id}:join-request:{$request->id}:submitted",
            subject: '【チーム運営】参加申請が届きました',
            text: implode("\n", [
                "「{$team->name}」に参加申請が届きました。",
                '次のリンクから、承認または却下してください。',
            ]),
            recipients: $recipients,
            url: config('team_ops.web_url')."/teams/{$team->id}/join-requests",
            tenantRef: "team:{$team->id}",
        );
    }

    /**
     * 1つのリンクに申請が集中したら、リンクの漏えいを疑って代表に知らせる(AC-002-10)。
     * 同じリンクについては1日1通まで(重複防止キーに日付を入れる)。
     */
    private function notifyRepresentativeOfFlood(Team $team, int $invitationId): void
    {
        $recipients = $this->membersWithRoles($team, [TeamRole::Representative]);
        if ($recipients === []) {
            return;
        }

        $this->outbox->queue(
            kind: 'invitation_flood',
            // 宛先も含める。同じ日に代表のアドレスが変わっても、内容の違う依頼を同じキーで出さないように
            dedupeKey: "team:{$team->id}:invitation:{$invitationId}:flood:".now()->format('Ymd').':'.substr(hash('sha256', implode(',', $recipients)), 0, 12),
            subject: '【チーム運営】招待リンクへの申請が多すぎます',
            text: implode("\n", [
                "「{$team->name}」の招待リンクに、24時間で".self::MAX_PER_LINK_PER_DAY.'件を超える参加申請がありました。',
                'それ以降の申請は受け付けていません。',
                'リンクがチームの外に広まっている可能性があります。必要なら、招待リンクを無効にして発行し直してください。',
            ]),
            recipients: $recipients,
            url: config('team_ops.web_url')."/teams/{$team->id}/invitation",
            tenantRef: "team:{$team->id}",
        );
    }

    /**
     * @param  list<TeamRole>  $roles
     * @return array<string, string> recipient_ref => メールアドレス
     */
    private function membersWithRoles(Team $team, array $roles): array
    {
        $userIds = TeamMemberRole::where('team_member_roles.team_id', $team->id)
            ->whereIn('team_member_roles.role', $roles)
            ->join('team_memberships', 'team_memberships.id', '=', 'team_member_roles.membership_id')
            ->pluck('team_memberships.user_id')
            ->unique();

        return User::whereIn('id', $userIds)->orderBy('id')->get()
            ->mapWithKeys(fn (User $u) => ['user_'.$u->id => $u->email])
            ->all();
    }
}
