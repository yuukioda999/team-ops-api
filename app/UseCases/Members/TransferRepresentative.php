<?php

namespace App\UseCases\Members;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamMemberRole;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 代表を、役員のメンバーに交代する(AC-004-04・05、ADR-0015)。
 * 新しい代表は役員を外して代表を付け、元の代表は代表を外して役員を付ける。コーチ・保護者はそのまま。
 */
final class TransferRepresentative
{
    public function __construct(private readonly MemberNotifier $notifier) {}

    public function __invoke(User $actor, Team $team, int $membershipId): TeamMembership
    {
        return DB::transaction(function () use ($actor, $team, $membershipId): TeamMembership {
            // 同時に交代されても、代表が0人や2人にならないよう、チームの行をロックしてから入れ替える
            Team::whereKey($team->id)->lockForUpdate()->first();
            $current = TeamMembership::where('team_id', $team->id)->where('user_id', $actor->id)->lockForUpdate()->sole();
            $target = TeamMembership::where('team_id', $team->id)->lockForUpdate()->findOrFail($membershipId);

            if (! in_array(TeamRole::Representative, $current->roleList(), true)) {
                // 権限の判定のあとに、別のリクエストで代表でなくなっていた場合
                abort(403);
            }
            if ($target->id === $current->id || ! in_array(TeamRole::Officer, $target->roleList(), true)) {
                throw ValidationException::withMessages(['membership_id' => '代表にできるのは役員のメンバーだけです。先に役員に任命してください。']);
            }

            TeamMemberRole::where('membership_id', $current->id)->where('role', TeamRole::Representative)->delete();
            TeamMemberRole::where('membership_id', $target->id)->where('role', TeamRole::Officer)->delete();
            $target->grant([TeamRole::Representative]);
            $current->grant([TeamRole::Officer]);

            $log = Audit::record($team, $actor, 'team.representative_transferred', $target, [
                'from_user_id' => $current->user_id,
                'to_user_id' => $target->user_id,
            ]);
            $this->notifier->rolesChanged($team, $target, $log, 'representative_transferred');
            $this->notifier->rolesChanged($team, $current, $log, 'representative_transferred');

            return $target->load(['user', 'roles']);
        });
    }
}
