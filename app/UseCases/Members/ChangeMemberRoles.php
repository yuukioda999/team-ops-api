<?php

namespace App\UseCases\Members;

use App\Enums\TeamRole;
use App\Models\PlayerGuardian;
use App\Models\Team;
use App\Models\TeamMemberRole;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * メンバーの役割を、指定した一覧に置き換える(AC-004-03・09、ADR-0015)。
 * 代表の役割はここでは動かさない(代表のメンバーは代表のまま、ほかの役割だけを変える)。
 * 保護者を外したら、選手との紐づけも外す(plan の判断8)。
 */
final class ChangeMemberRoles
{
    public function __construct(private readonly MemberNotifier $notifier) {}

    /**
     * @param  list<TeamRole>  $roles  代表を除く役割
     */
    public function __invoke(User $actor, Team $team, int $membershipId, array $roles): TeamMembership
    {
        $requested = array_values(array_filter(TeamRole::changeable(), fn (TeamRole $r) => in_array($r, $roles, true)));

        return DB::transaction(function () use ($actor, $team, $membershipId, $requested): TeamMembership {
            $member = TeamMembership::where('team_id', $team->id)->lockForUpdate()->findOrFail($membershipId);
            $before = $member->roleList();
            $isRepresentative = in_array(TeamRole::Representative, $before, true);

            // 役割が1つもない状態は作れない。代表のメンバーは代表が残るので空でもよい(AC-004-09)
            if ($requested === [] && ! $isRepresentative) {
                throw ValidationException::withMessages(['roles' => '役割をすべて外すと除名になります。除名する場合は、除名の操作を行ってください。']);
            }

            $removed = array_values(array_filter(TeamRole::changeable(), fn (TeamRole $r) => in_array($r, $before, true) && ! in_array($r, $requested, true)));
            TeamMemberRole::where('membership_id', $member->id)->whereIn('role', array_map(fn (TeamRole $r) => $r->value, $removed))->delete();
            $member->grant($requested);

            $unlinked = 0;
            if (in_array(TeamRole::Parent, $removed, true)) {
                $unlinked = PlayerGuardian::where('membership_id', $member->id)->delete();
            }

            $after = $member->roleList();
            if ($after !== $before) {
                $log = Audit::record($team, $actor, 'member.roles_changed', $member, [
                    'user_id' => $member->user_id,
                    'before' => array_map(fn (TeamRole $r) => $r->value, $before),
                    'after' => array_map(fn (TeamRole $r) => $r->value, $after),
                    'guardians_unlinked' => $unlinked,
                ]);
                $this->notifier->rolesChanged($team, $member, $log);
            }

            return $member->load(['user', 'roles']);
        });
    }
}
