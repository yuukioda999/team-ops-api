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
     * チームの外に置いてよいルート。チームのデータを返さないものだけ。
     * ここにないルートは、すべて /api/teams/{team} の下に置く(ADR-0009)。
     * 新しいルートをチームの外に足すときは、チームのデータを返さないことを確かめてからここに足す。
     *
     * @var array<string, bool> "メソッド URI" => ログインが必要か
     */
    private const array ROUTES_OUTSIDE_TEAMS = [
        'GET /' => false,
        'GET up' => false,
        'GET storage/{path}' => false,
        'PUT storage/{path}' => false,
        'GET api/health' => false,
        'GET api/sanctum/csrf-cookie' => false,
        'POST api/auth/login-links' => false,
        'POST api/auth/login-links/consume' => false,
        'POST api/auth/logout' => true,
        'GET api/me' => true,
        'PATCH api/me' => true,
        'POST api/me/email-changes' => true,
        'POST api/me/email-changes/consume' => true,
        'POST api/teams' => true,
    ];

    /**
     * ADR-0009:チームのデータを扱うルートは、すべて /api/teams/{team} の下に置き、ログインを必須にする。
     * Controller の名前空間によらず、許可リストにないルートがチームの外にあれば、このテストが落ちる。
     */
    public function test_AC_000_01_every_route_outside_the_allowlist_is_under_the_team_prefix_and_requires_login(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes());
        $this->assertNotEmpty($routes->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/teams/{team}')));

        foreach ($routes as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $label = "{$method} {$route->uri()}";
                $requiresLogin = in_array('auth:sanctum', $route->gatherMiddleware(), true);

                if (array_key_exists($label, self::ROUTES_OUTSIDE_TEAMS)) {
                    if (self::ROUTES_OUTSIDE_TEAMS[$label]) {
                        $this->assertTrue($requiresLogin, "{$label} must require login");
                    }

                    continue;
                }

                $this->assertStringStartsWith('api/teams/{team}', $route->uri(), "{$label} is outside api/teams/{team}; add it to the allowlist only if it returns no team data");
                $this->assertTrue($requiresLogin, "{$label} must require login");
            }
        }
    }
}
