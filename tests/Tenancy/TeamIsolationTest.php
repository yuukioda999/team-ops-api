<?php

namespace Tests\Tenancy;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * AC-000-01:他のチームのデータは、存在の有無も分からない 404 を返す。
 */
class TeamIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_AC_000_01_another_teams_data_returns_the_same_404_as_a_missing_team(): void
    {
        $outsider = User::factory()->create();
        $otherTeam = Team::factory()->withRepresentative()->create();
        $missing = '01jzzzzzzzzzzzzzzzzzzzzzzz';

        $existing = $this->actingAs($outsider)->getJson("/api/teams/{$otherTeam->id}");
        $notExisting = $this->actingAs($outsider)->getJson("/api/teams/{$missing}");

        $existing->assertNotFound();
        $notExisting->assertNotFound();
        $this->assertSame($notExisting->getContent(), $existing->getContent());
    }

    public function test_AC_000_01_a_malformed_team_id_returns_the_same_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/teams/1')->assertNotFound()->assertExactJson(['message' => '見つかりません']);
        $this->actingAs($user)->getJson("/api/teams/1' OR '1'='1")->assertNotFound();
    }

    public function test_AC_000_01_leaving_a_team_cuts_access_immediately(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->withRepresentative()->create(['created_by' => $user->id]);
        $this->actingAs($user)->getJson("/api/teams/{$team->id}")->assertOk();

        $team->members()->detach($user->id);

        $this->actingAs($user)->getJson("/api/teams/{$team->id}")->assertNotFound();
    }

    /**
     * ADR-0009:チームのデータを扱うルートは、すべて /api/teams/{team} の下に置き、ログインを必須にする。
     * App\Http\Controllers\Teams の Controller をこの外のルートで使うと、このテストが落ちる
     * (チームを作る POST /api/teams だけは例外)。
     */
    public function test_AC_000_01_every_team_scoped_route_is_under_the_team_prefix_and_requires_login(): void
    {
        $teamRoutes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\Teams\\'));

        $this->assertNotEmpty($teamRoutes);
        foreach ($teamRoutes as $route) {
            $label = implode('|', $route->methods()).' '.$route->uri();
            $this->assertContains('auth:sanctum', $route->gatherMiddleware(), "{$label} must require login");
            if ($route->uri() === 'api/teams' && in_array('POST', $route->methods(), true)) {
                continue;
            }
            $this->assertStringStartsWith('api/teams/{team}', $route->uri(), "{$label} must be under api/teams/{team}");
        }
    }
}
