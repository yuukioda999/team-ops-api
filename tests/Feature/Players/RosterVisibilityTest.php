<?php

namespace Tests\Feature\Players;

use App\Enums\Grade;
use App\Enums\TeamRole;
use App\Models\AuditLog;

/**
 * 名簿の閲覧範囲と、閲覧の監査ログ(AC-003-08〜12、ADR-0013)。
 */
class RosterVisibilityTest extends PlayerTestCase
{
    public function test_AC_003_08_representatives_officers_and_coaches_see_every_player_with_guardians(): void
    {
        $parent = $this->member([TeamRole::Parent], '山田 母');
        $taro = $this->player('山田 太郎', Grade::E3, 10);
        $this->player('佐藤 次郎', Grade::E1, null);
        $this->link($taro, $parent);

        foreach ([$this->representative, $this->member([TeamRole::Officer]), $this->member([TeamRole::Coach])] as $viewer) {
            $this->actingAs($viewer)->getJson($this->url())
                ->assertOk()
                ->assertJsonPath('scope', 'all')
                ->assertJsonCount(2, 'players')
                // 学年の順(小1 → 小3)
                ->assertJsonPath('players.0.display_name', '佐藤 次郎')
                ->assertJsonPath('players.1.display_name', '山田 太郎')
                ->assertJsonPath('players.1.guardians.0.display_name', '山田 母');
        }
    }

    public function test_AC_003_09_a_parent_sees_only_their_own_children_and_their_other_guardians(): void
    {
        $mother = $this->member([TeamRole::Parent], '山田 母');
        $father = $this->member([TeamRole::Parent], '山田 父');
        $neighbor = $this->member([TeamRole::Parent], '佐藤 母');
        $taro = $this->player('山田 太郎', Grade::E3);
        $hanako = $this->player('山田 花子', Grade::E1);
        $jiro = $this->player('佐藤 次郎', Grade::E3);
        $this->link($taro, $mother);
        $this->link($taro, $father);
        $this->link($hanako, $mother);
        $this->link($jiro, $neighbor);

        $response = $this->actingAs($mother)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('scope', 'own')
            ->assertJsonCount(2, 'players');
        $this->assertSame(['山田 花子', '山田 太郎'], array_column((array) $response->json('players'), 'display_name'));
        $this->assertSame(['山田 母', '山田 父'], array_column((array) $response->json('players.1.guardians'), 'display_name'));
        $this->assertStringNotContainsString('佐藤', (string) $response->getContent());

        $this->actingAs($father)->getJson($this->url())->assertJsonCount(1, 'players');
    }

    public function test_AC_003_09_a_parent_who_is_also_a_coach_sees_everyone(): void
    {
        $coachParent = $this->member([TeamRole::Coach, TeamRole::Parent]);
        $this->player('A');
        $this->player('B');

        $this->actingAs($coachParent)->getJson($this->url())->assertJsonPath('scope', 'all')->assertJsonCount(2, 'players');
    }

    public function test_AC_003_09_a_parent_without_children_sees_an_empty_roster(): void
    {
        $this->player();

        $this->actingAs($this->member([TeamRole::Parent]))->getJson($this->url())
            ->assertOk()->assertExactJson(['scope' => 'own', 'players' => []]);
    }

    public function test_AC_003_10_a_parent_gets_404_for_a_player_not_linked_to_them(): void
    {
        $mother = $this->member([TeamRole::Parent]);
        $own = $this->player('山田 太郎');
        $other = $this->player('佐藤 次郎');
        $this->link($own, $mother);

        $this->actingAs($mother)->getJson($this->url("/{$own->id}"))->assertOk()->assertJsonPath('player.display_name', '山田 太郎');

        $notLinked = $this->actingAs($mother)->getJson($this->url("/{$other->id}"))->assertNotFound();
        $missing = $this->actingAs($mother)->getJson($this->url('/999999'))->assertNotFound();
        $this->assertSame($missing->getContent(), $notLinked->getContent());
    }

    public function test_AC_003_10_a_coach_can_open_any_player_of_the_team(): void
    {
        $player = $this->player();

        $this->actingAs($this->member([TeamRole::Coach]))->getJson($this->url("/{$player->id}"))->assertOk();
    }

    public function test_AC_003_11_every_list_and_detail_view_is_audited_with_the_scope(): void
    {
        $mother = $this->member([TeamRole::Parent]);
        $coach = $this->member([TeamRole::Coach]);
        $player = $this->player('山田 太郎', Grade::E3, 10);
        $this->link($player, $mother);

        $this->actingAs($coach)->getJson($this->url().'?grade=e3&number=10')->assertOk();
        $this->actingAs($coach)->getJson($this->url())->assertOk();
        $this->actingAs($mother)->getJson($this->url())->assertOk();
        $this->actingAs($mother)->getJson($this->url("/{$player->id}"))->assertOk();

        $logs = $this->audits('player.viewed_list', 'player.viewed');
        $this->assertCount(4, $logs);
        $this->assertSame([$coach->id, $coach->id, $mother->id, $mother->id], array_map(fn (AuditLog $l) => $l->actor_user_id, $logs));
        $this->assertEquals(['scope' => 'all', 'grade' => 'e3', 'number' => 10, 'count' => 1], $logs[0]->details);
        $this->assertEquals(['scope' => 'all', 'grade' => null, 'number' => null, 'count' => 1], $logs[1]->details);
        $this->assertEquals(['scope' => 'own', 'grade' => null, 'number' => null, 'count' => 1], $logs[2]->details);
        $this->assertSame('player.viewed', $logs[3]->action);
        $this->assertSame($player->id, $logs[3]->subject_id);
        $this->assertEquals(['scope' => 'own'], $logs[3]->details);
        $this->assertTrue($logs[3]->created_at->greaterThan(now()->subMinute()));
    }

    public function test_AC_003_11_a_404_detail_view_is_not_recorded_as_viewed(): void
    {
        $mother = $this->member([TeamRole::Parent]);
        $other = $this->player();

        $this->actingAs($mother)->getJson($this->url("/{$other->id}"))->assertNotFound();

        $this->assertNull($this->latestAudit('player.viewed'));
    }

    public function test_AC_003_12_filters_by_grade_and_number(): void
    {
        $this->player('A', Grade::E3, 10);
        $this->player('B', Grade::E3, 7);
        $this->player('C', Grade::E4, 10);

        $names = fn (string $query) => array_column((array) $this->actingAs($this->representative)->getJson($this->url().$query)->assertOk()->json('players'), 'display_name');

        $this->assertSame(['B', 'A'], $names('?grade=e3'));
        $this->assertSame(['A', 'C'], $names('?number=10'));
        $this->assertSame(['A'], $names('?grade=e3&number=10'));
        $this->assertSame([], $names('?grade=j1'));

        $this->actingAs($this->representative)->getJson($this->url().'?grade=e9')->assertUnprocessable()->assertJsonValidationErrors(['grade']);
        $this->actingAs($this->representative)->getJson($this->url().'?number=abc')->assertUnprocessable()->assertJsonValidationErrors(['number']);
    }

    public function test_AC_003_08_the_team_tells_the_screen_what_the_member_may_do_with_the_roster(): void
    {
        $cases = [
            [$this->representative, true, true],
            [$this->member([TeamRole::Officer]), true, true],
            [$this->member([TeamRole::Coach]), false, true],
            [$this->member([TeamRole::Parent]), false, false],
        ];
        foreach ($cases as [$user, $manage, $viewAll]) {
            $this->actingAs($user)->getJson("/api/teams/{$this->team->id}")
                ->assertJsonPath('permissions.manage_players', $manage)
                ->assertJsonPath('permissions.view_all_players', $viewAll);
        }
    }
}
