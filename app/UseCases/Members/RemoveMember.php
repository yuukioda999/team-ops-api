<?php

namespace App\UseCases\Members;

use App\Enums\TeamRole;
use App\Models\PlayerGuardian;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * メンバーを除名する(AC-004-06〜08、ADR-0015)。メンバーの行を消し、役割と選手の紐づけも一緒に消える。
 * 本人にはメールしない。招待リンクから再び申請できる。
 */
final class RemoveMember
{
    public function __invoke(User $actor, Team $team, int $membershipId): void
    {
        DB::transaction(function () use ($actor, $team, $membershipId): void {
            $target = TeamMembership::where('team_id', $team->id)->lockForUpdate()->findOrFail($membershipId);

            // 相手の役割によって変わる判定なので、相手を見つけてから Policy で判定する(AC-004-07)
            Gate::forUser($actor)->authorize('removeMember', [$team, $target]);

            if ($target->user_id === $actor->id) {
                throw ValidationException::withMessages(['member' => '自分を除名することはできません。先に代表を交代してください。']);
            }

            $roles = $target->roleList();
            $unlinked = PlayerGuardian::where('membership_id', $target->id)->count();
            $target->delete(); // 役割(team_member_roles)と紐づけ(player_guardians)は外部キーで一緒に消える

            Audit::record($team, $actor, 'member.removed', $target, [
                'user_id' => $target->user_id,
                'roles' => array_map(fn (TeamRole $r) => $r->value, $roles),
                'guardians_unlinked' => $unlinked,
            ]);
        });
    }
}
