<?php

namespace App\UseCases\JoinRequests;

use App\Enums\JoinRequestStatus;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\NotifyRelay\EmailOutbox;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 承認待ちの申請を、選んだ役割(保護者・コーチ)で承認する(AC-002-12・14)。
 */
final class ApproveJoinRequest
{
    public function __construct(private readonly EmailOutbox $outbox) {}

    /**
     * @param  list<TeamRole>  $roles  保護者・コーチのいずれか、または両方
     */
    public function __invoke(User $actor, Team $team, int $requestId, array $roles): TeamJoinRequest
    {
        if ($roles === [] || array_diff(array_map(fn (TeamRole $r) => $r->value, $roles), array_map(fn (TeamRole $r) => $r->value, TeamRole::grantableOnApproval())) !== []) {
            throw new InvalidArgumentException('Only parent and coach can be granted on approval (AC-002-14).');
        }

        return DB::transaction(function () use ($actor, $team, $requestId, $roles): TeamJoinRequest {
            $request = TeamJoinRequest::where('team_id', $team->id)
                ->where('status', JoinRequestStatus::Pending)
                ->lockForUpdate()
                ->findOrFail($requestId);

            $team->members()->syncWithoutDetaching([$request->user_id]);
            TeamMembership::where('team_id', $team->id)->where('user_id', $request->user_id)->sole()->grant($roles);
            $request->finish(JoinRequestStatus::Approved, $actor);

            Audit::record($team, $actor, 'join_request.approved', $request, [
                'user_id' => $request->user_id,
                'roles' => array_map(fn (TeamRole $r) => $r->value, $roles),
            ]);

            $applicant = $request->user()->firstOrFail();
            $this->outbox->queue(
                kind: 'join_request_approved',
                dedupeKey: "team:{$team->id}:join-request:{$request->id}:approved",
                subject: '【チーム運営】参加が承認されました',
                text: "「{$team->name}」への参加が承認されました。次のリンクから、チームの画面を開けます。",
                recipients: ['user_'.$applicant->id => $applicant->email],
                url: config('team_ops.web_url')."/teams/{$team->id}",
                tenantRef: "team:{$team->id}",
            );

            return $request;
        });
    }
}
