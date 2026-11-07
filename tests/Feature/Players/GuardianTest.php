<?php

namespace Tests\Feature\Players;

use App\Enums\TeamRole;
use App\Models\PlayerGuardian;
use App\Models\Team;
use App\Models\User;
use Database\Factories\TeamFactory;

/**
 * 選手と保護者の紐づけ(AC-003-05〜07・20)。
 */
class GuardianTest extends PlayerTestCase
{
    public function test_AC_003_05_parents_are_linked_to_a_player_up_to_four(): void
    {
        $player = $this->player();
        $parents = array_map(fn (int $i) => $this->member([TeamRole::Parent], "保護者{$i}"), range(1, 5));
        $ids = array_map(fn (User $u) => $this->membershipOf($u)->id, $parents);

        $this->actingAs($this->representative)->putJson($this->url("/{$player->id}/guardians"), ['membership_ids' => array_slice($ids, 0, 4)])
            ->assertOk()
            ->assertJsonCount(4, 'player.guardians')
            ->assertJsonPath('player.guardians.0', ['membership_id' => $ids[0], 'display_name' => '保護者1']);

        $this->actingAs($this->representative)->putJson($this->url("/{$player->id}/guardians"), ['membership_ids' => $ids])
            ->assertUnprocessable()->assertJsonValidationErrors(['membership_ids']);
        $this->assertSame(4, PlayerGuardian::where('player_id', $player->id)->count());
    }

    public function test_AC_003_05_one_parent_can_be_linked_to_siblings(): void
    {
        $parent = $this->member([TeamRole::Parent, TeamRole::Coach]);
        $id = $this->membershipOf($parent)->id;
        foreach ([$this->player('兄'), $this->player('妹')] as $player) {
            $this->actingAs($this->representative)->putJson($this->url("/{$player->id}/guardians"), ['membership_ids' => [$id]])->assertOk();
        }
        $this->assertSame(2, PlayerGuardian::where('membership_id', $id)->count());
    }

    public function test_AC_003_06_unlinking_keeps_the_member(): void
    {
        $player = $this->player();
        $parent = $this->member([TeamRole::Parent]);
        $this->link($player, $parent);

        $this->actingAs($this->representative)->putJson($this->url("/{$player->id}/guardians"), ['membership_ids' => []])
            ->assertOk()->assertJsonPath('player.guardians', []);

        $this->assertSame(0, PlayerGuardian::count());
        $this->assertSame([TeamRole::Parent], $this->membershipOf($parent)->roleList());
    }

    public function test_AC_003_07_members_without_the_parent_role_are_not_candidates_and_cannot_be_linked(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $both = $this->member([TeamRole::Coach, TeamRole::Parent], '兼任');

        $this->actingAs($this->representative)->getJson("/api/teams/{$this->team->id}/guardian-candidates")
            ->assertOk()
            ->assertExactJson(['candidates' => [
                ['membership_id' => $this->membershipOf($parent)->id, 'display_name' => '保護者'],
                ['membership_id' => $this->membershipOf($both)->id, 'display_name' => '兼任'],
            ]]);

        $player = $this->player();
        foreach ([$coach, $this->representative] as $notParent) {
            $this->actingAs($this->representative)->putJson($this->url("/{$player->id}/guardians"), ['membership_ids' => [$this->membershipOf($notParent)->id]])
                ->assertUnprocessable()
                ->assertJsonPath('errors.membership_ids.0', '保護者の役割を持つメンバーだけを紐づけられます。');
        }
        $this->assertSame(0, PlayerGuardian::count());
    }

    public function test_AC_003_07_a_parent_of_another_team_cannot_be_linked(): void
    {
        $other = Team::factory()->withRepresentative()->create();
        $outsider = User::factory()->create();
        $membership = TeamFactory::addMember($other, $outsider, [TeamRole::Parent]);
        $player = $this->player();

        $this->actingAs($this->representative)->putJson($this->url("/{$player->id}/guardians"), ['membership_ids' => [$membership->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['membership_ids']);
        $this->assertSame(0, PlayerGuardian::count());
    }

    public function test_AC_003_07_only_representatives_and_officers_manage_links(): void
    {
        $player = $this->player();
        foreach ([[TeamRole::Coach], [TeamRole::Parent]] as $roles) {
            $member = $this->member($roles);
            $this->actingAs($member)->getJson("/api/teams/{$this->team->id}/guardian-candidates")->assertForbidden();
            $this->actingAs($member)->putJson($this->url("/{$player->id}/guardians"), ['membership_ids' => []])->assertForbidden();
            $this->actingAs($member)->putJson($this->url("/{$player->id}/guardians"), ['membership_ids' => 'x'])->assertForbidden();
        }
        $officer = $this->member([TeamRole::Officer]);
        $this->actingAs($officer)->getJson("/api/teams/{$this->team->id}/guardian-candidates")->assertOk();
    }

    public function test_AC_003_20_linking_and_unlinking_are_audited_with_membership_ids_only(): void
    {
        $player = $this->player();
        $a = $this->membershipOf($this->member([TeamRole::Parent], '母'))->id;
        $b = $this->membershipOf($this->member([TeamRole::Parent], '父'))->id;

        $this->actingAs($this->representative)->putJson($this->url("/{$player->id}/guardians"), ['membership_ids' => [$a]])->assertOk();
        $this->actingAs($this->representative)->putJson($this->url("/{$player->id}/guardians"), ['membership_ids' => [$b]])->assertOk();
        $this->actingAs($this->representative)->putJson($this->url("/{$player->id}/guardians"), ['membership_ids' => [$b]])->assertOk(); // 変化なし

        $logs = $this->audits('player.guardians_changed');
        $this->assertCount(2, $logs);
        $this->assertSame(['added' => [(string) $a], 'removed' => []], $logs[0]->details);
        $this->assertSame(['added' => [(string) $b], 'removed' => [(string) $a]], $logs[1]->details);
        $this->assertSame($player->id, $logs[1]->subject_id);
    }
}
