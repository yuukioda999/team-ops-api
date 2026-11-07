<?php

namespace Tests\Tenancy;

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Audit\Audit;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AC-000-01:他のチームのメンバーは、存在しない場合と同じ404。他のチームの設定・監査ログ・メンバーにも触れない。
 */
class MemberIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_AC_000_01_another_teams_membership_is_not_found_through_my_team(): void
    {
        $rep = User::factory()->create();
        $mine = Team::factory()->withRepresentative()->create(['created_by' => $rep->id]);
        $other = Team::factory()->withRepresentative()->create();
        $officerThere = TeamFactory::addMember($other, User::factory()->create(), [TeamRole::Officer]);
        $base = "/api/teams/{$mine->id}";

        $missing = $this->actingAs($rep)->putJson("{$base}/members/999999/roles", ['roles' => ['coach']])->assertNotFound()->getContent();
        $this->assertSame($missing, $this->actingAs($rep)->putJson("{$base}/members/{$officerThere->id}/roles", ['roles' => ['coach']])->assertNotFound()->getContent());
        $this->actingAs($rep)->deleteJson("{$base}/members/{$officerThere->id}")->assertNotFound();
        $this->actingAs($rep)->postJson("{$base}/representative", ['membership_id' => $officerThere->id])->assertNotFound();

        $this->assertSame([TeamRole::Officer], $officerThere->fresh()?->roleList());
        $this->assertSame(2, TeamMembership::where('team_id', $other->id)->count());
    }

    public function test_AC_000_01_a_non_member_gets_404_for_every_member_route(): void
    {
        $outsider = User::factory()->create();
        $team = Team::factory()->withRepresentative()->create();
        $repMembership = TeamMembership::where('team_id', $team->id)->sole();
        $base = "/api/teams/{$team->id}";

        $this->actingAs($outsider)->getJson("{$base}/members")->assertNotFound();
        $this->actingAs($outsider)->putJson("{$base}/members/{$repMembership->id}/roles", ['roles' => []])->assertNotFound();
        $this->actingAs($outsider)->deleteJson("{$base}/members/{$repMembership->id}")->assertNotFound();
        $this->actingAs($outsider)->postJson("{$base}/representative", ['membership_id' => $repMembership->id])->assertNotFound();
        $this->actingAs($outsider)->patchJson($base, ['name' => '乗っ取り'])->assertNotFound();
        $this->actingAs($outsider)->getJson("{$base}/audit-logs")->assertNotFound();
        $this->assertNotSame('乗っ取り', $team->fresh()?->name);
    }

    public function test_AC_000_01_only_this_teams_audit_logs_are_shown(): void
    {
        $rep = User::factory()->create();
        $mine = Team::factory()->withRepresentative()->create(['created_by' => $rep->id]);
        $other = Team::factory()->withRepresentative()->create();
        Audit::record($other, null, 'invitation.issued');

        $this->actingAs($rep)->getJson("/api/teams/{$mine->id}/audit-logs")->assertOk()->assertJsonPath('logs', []);
        $this->assertSame(1, AuditLog::count());
    }

    public function test_AC_000_01_members_of_other_teams_are_not_listed(): void
    {
        $rep = User::factory()->create(['display_name' => '自分']);
        $mine = Team::factory()->withRepresentative()->create(['created_by' => $rep->id]);
        $other = Team::factory()->withRepresentative()->create();
        TeamFactory::addMember($other, User::factory()->create(['display_name' => '他チームの人']), [TeamRole::Parent]);

        $this->actingAs($rep)->getJson("/api/teams/{$mine->id}/members")
            ->assertOk()->assertJsonCount(1, 'members')->assertJsonPath('members.0.display_name', '自分');
    }
}
