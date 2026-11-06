<?php

namespace Tests\Feature\Invitations;

use App\Enums\JoinRequestStatus;
use App\Enums\TeamRole;
use App\Models\TeamJoinRequest;
use App\Models\TeamMemberRole;
use App\Models\TeamMembership;
use App\Models\User;
use Database\Factories\TeamFactory;
use Illuminate\Database\QueryException;

/**
 * 同時に操作されても崩れない決まりを、DB の一意制約で守っていることを確かめる(ADR-0011、AC-002-09)。
 */
class ConstraintTest extends InvitationTestCase
{
    public function test_ADR_0011_a_team_cannot_have_two_representatives(): void
    {
        $other = User::factory()->create(['email' => 'other@example.invalid']);
        $membership = TeamFactory::addMember($this->team, $other, [TeamRole::Parent]);

        $this->expectException(QueryException::class);
        TeamMemberRole::create([
            'membership_id' => $membership->id,
            'team_id' => $this->team->id,
            'role' => TeamRole::Representative,
            'representative_of' => $this->team->id,
        ]);
    }

    public function test_ADR_0011_the_same_role_cannot_be_held_twice(): void
    {
        $membership = TeamMembership::where('team_id', $this->team->id)->where('user_id', $this->representative->id)->sole();

        $this->expectException(QueryException::class);
        TeamMemberRole::create(['membership_id' => $membership->id, 'team_id' => $this->team->id, 'role' => TeamRole::Representative]);
    }

    public function test_AC_002_09_a_person_cannot_have_two_pending_requests_for_one_team(): void
    {
        $applicant = $this->applicant();
        $pending = fn () => TeamJoinRequest::create([
            'team_id' => $this->team->id,
            'user_id' => $applicant->id,
            'status' => JoinRequestStatus::Pending,
            'pending_user_id' => $applicant->id,
        ]);
        $pending()->finish(JoinRequestStatus::Rejected, $this->representative);
        $pending(); // 最終状態の申請は数えない

        $this->expectException(QueryException::class);
        $pending();
    }
}
