<?php

namespace Tests\Tenancy;

use App\Enums\Grade;
use App\Enums\TeamRole;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * AC-000-01:他のチームの選手は、存在しない場合と同じ404。名簿の数え方・重複の判定にも他チームを混ぜない。
 */
class PlayerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $representative;

    private Team $mine;

    private Team $other;

    private Player $otherPlayer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->representative = User::factory()->create();
        $this->mine = Team::factory()->withRepresentative()->create(['created_by' => $this->representative->id]);
        $this->other = Team::factory()->withRepresentative()->create();
        $this->otherPlayer = Player::create(['team_id' => $this->other->id, 'display_name' => '他チームの選手', 'grade' => Grade::E3, 'number' => 1]);
    }

    public function test_AC_000_01_another_teams_player_is_not_found_through_my_team(): void
    {
        $base = "/api/teams/{$this->mine->id}/players";
        $id = $this->otherPlayer->id;
        $missing = $this->actingAs($this->representative)->getJson("{$base}/999999")->assertNotFound()->getContent();

        $this->assertSame($missing, $this->actingAs($this->representative)->getJson("{$base}/{$id}")->assertNotFound()->getContent());
        $this->actingAs($this->representative)->patchJson("{$base}/{$id}", ['grade' => 'e6'])->assertNotFound();
        $this->actingAs($this->representative)->deleteJson("{$base}/{$id}")->assertNotFound();
        $this->actingAs($this->representative)->putJson("{$base}/{$id}/guardians", ['membership_ids' => []])->assertNotFound();

        $this->assertSame(Grade::E3, $this->otherPlayer->fresh()?->grade);
        $this->assertSame([], $this->actingAs($this->representative)->getJson($base)->json('players'));
    }

    public function test_AC_000_01_a_non_member_gets_404_for_every_roster_route(): void
    {
        $base = "/api/teams/{$this->other->id}";
        $id = $this->otherPlayer->id;
        $csv = UploadedFile::fake()->createWithContent('p.csv', "表示名,学年\nA,小1\n");

        $this->actingAs($this->representative)->getJson("{$base}/players")->assertNotFound();
        $this->actingAs($this->representative)->postJson("{$base}/players", ['display_name' => 'A', 'grade' => 'e1'])->assertNotFound();
        $this->actingAs($this->representative)->getJson("{$base}/players/{$id}")->assertNotFound();
        $this->actingAs($this->representative)->getJson("{$base}/guardian-candidates")->assertNotFound();
        $this->actingAs($this->representative)->getJson("{$base}/players/import/template")->assertNotFound();
        $this->actingAs($this->representative)->post("{$base}/players/import/preview", ['file' => $csv], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame(1, Player::where('team_id', $this->other->id)->count());
    }

    public function test_AC_000_01_a_parent_linked_in_another_team_sees_nothing_here(): void
    {
        $parent = User::factory()->create();
        $otherMembership = TeamFactory::addMember($this->other, $parent, [TeamRole::Parent]);
        $this->otherPlayer->guardians()->create(['membership_id' => $otherMembership->id]);
        TeamFactory::addMember($this->mine, $parent, [TeamRole::Parent]);

        $this->actingAs($parent)->getJson("/api/teams/{$this->mine->id}/players")->assertOk()->assertJsonPath('players', []);
        $this->actingAs($parent)->getJson("/api/teams/{$this->mine->id}/players/{$this->otherPlayer->id}")->assertNotFound();
        $this->actingAs($parent)->getJson("/api/teams/{$this->other->id}/players")->assertJsonCount(1, 'players');
    }

    public function test_AC_000_01_duplicates_are_checked_within_the_team_only(): void
    {
        $this->actingAs($this->representative)->postJson("/api/teams/{$this->mine->id}/players", ['display_name' => '他チームの選手', 'grade' => 'e3'])
            ->assertCreated()->assertJsonPath('possible_duplicate', false);

        $csv = UploadedFile::fake()->createWithContent('p.csv', "表示名,学年\n他チームの選手,小3\n");
        $this->actingAs($this->representative)->post("/api/teams/{$this->mine->id}/players/import/preview", ['file' => $csv], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('warnings.0.message', '2行目:同じ表示名・学年の選手がすでに名簿にいます');
        $this->assertSame(1, TeamMembership::where('team_id', $this->mine->id)->count());
    }

    public function test_AC_000_01_a_player_id_too_long_for_an_integer_returns_404(): void
    {
        $this->actingAs($this->representative)->getJson("/api/teams/{$this->mine->id}/players/99999999999999999999")
            ->assertNotFound()->assertExactJson(['message' => '見つかりません']);
    }
}
