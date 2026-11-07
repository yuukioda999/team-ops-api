<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 001 チームの作成と切り替え(AC-001-11〜14)。
 */
class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_AC_001_11_creating_a_team_makes_the_creator_its_representative(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/teams', ['name' => '  つくばFC U-10 ', 'sport' => 'soccer'])
            ->assertCreated()
            ->assertJsonPath('name', 'つくばFC U-10')
            ->assertJsonPath('sport', 'soccer')
            ->assertJsonPath('roles', ['representative'])
            ->assertJsonPath('permissions', ['manage_invitation' => true, 'review_join_requests' => true, 'manage_players' => true, 'view_players' => true, 'view_all_players' => true]);

        $team = Team::findOrFail((string) $response->json('id'));
        $this->assertSame($user->id, $team->created_by);
        $membership = TeamMembership::where('team_id', $team->id)->sole();
        $this->assertSame($user->id, $membership->user_id);
        $this->assertSame([TeamRole::Representative], $membership->roleList());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidTeams(): array
    {
        return [
            'missing name' => [['sport' => 'soccer'], 'errors.name.0'],
            'blank name' => [['name' => '   ', 'sport' => 'soccer'], 'errors.name.0'],
            'name over 50 characters' => [['name' => str_repeat('あ', 51), 'sport' => 'soccer'], 'errors.name.0'],
            'unknown sport' => [['name' => 'FC', 'sport' => 'cricket'], 'errors.sport.0'],
            'missing sport' => [['name' => 'FC'], 'errors.sport.0'],
            'full-width spaces only' => [['name' => "\u{3000}\u{3000}", 'sport' => 'soccer'], 'errors.name.0'],
            'a line break' => [['name' => "つくば\nFC", 'sport' => 'soccer'], 'errors.name.0'],
            'a right-to-left override' => [['name' => "FC\u{202E}abc", 'sport' => 'soccer'], 'errors.name.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidTeams')]
    public function test_AC_001_11_AC_000_05_invalid_input_is_rejected_per_field(array $input, string $path): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/teams', $input)
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors'])
            ->assertJsonPath($path, fn ($message) => is_string($message) && $message !== '');

        $this->assertSame(0, Team::count());
    }

    public function test_AC_001_11_all_three_sports_can_be_chosen(): void
    {
        $user = User::factory()->create();
        foreach (['soccer', 'baseball', 'other'] as $sport) {
            $this->actingAs($user)->postJson('/api/teams', ['name' => "FC {$sport}", 'sport' => $sport])->assertCreated();
        }
        $this->assertSame(3, Team::count());
    }

    public function test_AC_001_12_a_representative_can_create_up_to_five_teams(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->actingAs($user)->postJson('/api/teams', ['name' => "FC {$i}", 'sport' => 'soccer'])->assertCreated();
        }

        $this->actingAs($user)->postJson('/api/teams', ['name' => 'FC 6', 'sport' => 'soccer'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.team.0', '代表として作成できるチームは5つまでです。');

        $this->assertSame(5, Team::count());
    }

    public function test_AC_001_12_with_four_teams_only_one_more_can_be_created(): void
    {
        $user = User::factory()->create();
        Team::factory()->count(4)->withRepresentative()->create(['created_by' => $user->id]);

        $this->actingAs($user)->postJson('/api/teams', ['name' => 'FC 5', 'sport' => 'soccer'])->assertCreated();
        $this->actingAs($user)->postJson('/api/teams', ['name' => 'FC 6', 'sport' => 'soccer'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.team.0', '代表として作成できるチームは5つまでです。');
    }

    public function test_AC_001_12_the_limit_is_per_person(): void
    {
        $first = User::factory()->create();
        Team::factory()->count(5)->withRepresentative()->create(['created_by' => $first->id]);

        $this->actingAs(User::factory()->create())->postJson('/api/teams', ['name' => 'FC', 'sport' => 'soccer'])->assertCreated();
    }

    public function test_AC_001_13_a_member_of_several_teams_sees_each_team_by_its_id(): void
    {
        $user = User::factory()->create();
        $a = Team::factory()->withRepresentative()->create(['name' => 'チームA', 'created_by' => $user->id]);
        $b = Team::factory()->withRepresentative()->create(['name' => 'チームB', 'created_by' => $user->id]);

        $this->actingAs($user)->getJson("/api/teams/{$a->id}")->assertOk()->assertJsonPath('name', 'チームA');
        $this->actingAs($user)->getJson("/api/teams/{$b->id}")->assertOk()->assertJsonPath('name', 'チームB');
        $this->actingAs($user)->getJson('/api/teams/'.strtoupper($b->id))->assertOk()->assertJsonPath('name', 'チームB');
    }

    public function test_AC_001_13_AC_001_14_me_lists_the_teams_and_their_roles(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->withRepresentative()->create(['name' => 'チームA', 'created_by' => $user->id]);

        $this->actingAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('teams', [['id' => $team->id, 'name' => 'チームA', 'sport' => 'soccer', 'roles' => ['representative']]]);
    }

    public function test_AC_001_14_someone_in_no_team_gets_an_empty_list(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/me')->assertOk()->assertJsonPath('teams', []);
    }

    public function test_AC_000_02_team_apis_require_login(): void
    {
        $team = Team::factory()->withRepresentative()->create();

        $this->postJson('/api/teams', ['name' => 'FC', 'sport' => 'soccer'])->assertUnauthorized();
        $this->getJson("/api/teams/{$team->id}")->assertUnauthorized();
    }
}
