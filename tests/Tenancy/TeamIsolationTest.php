<?php

namespace Tests\Tenancy;

use App\Enums\JoinRequestStatus;
use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Queue;
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

    public function test_AC_000_01_a_pending_applicant_cannot_see_the_team_or_its_applications(): void
    {
        Queue::fake();
        $representative = User::factory()->create();
        $team = Team::factory()->withRepresentative()->create(['created_by' => $representative->id]);
        $url = (string) $this->actingAs($representative)->postJson("/api/teams/{$team->id}/invitation")->json('invitation.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $applicant = User::factory()->create();
        $this->actingAs($applicant)->postJson('/api/invitations/applications', ['token' => $query['token'], 'display_name' => '申請者'])->assertCreated();

        foreach (["/api/teams/{$team->id}", "/api/teams/{$team->id}/join-requests", "/api/teams/{$team->id}/invitation"] as $path) {
            $this->actingAs($applicant)->getJson($path)->assertNotFound();
        }
    }

    public function test_AC_000_01_another_teams_officer_cannot_review_this_teams_applications(): void
    {
        Queue::fake();
        $team = Team::factory()->withRepresentative()->create();
        $otherRepresentative = User::factory()->create();
        Team::factory()->withRepresentative()->create(['created_by' => $otherRepresentative->id]);
        $applicant = User::factory()->create();
        $request = TeamJoinRequest::create(['team_id' => $team->id, 'user_id' => $applicant->id, 'status' => JoinRequestStatus::Pending, 'pending_user_id' => $applicant->id]);

        $this->actingAs($otherRepresentative)->getJson("/api/teams/{$team->id}/join-requests")->assertNotFound();
        $this->actingAs($otherRepresentative)->postJson("/api/teams/{$team->id}/join-requests/{$request->id}/approve", ['roles' => ['parent']])->assertNotFound();
        $this->assertSame(JoinRequestStatus::Pending, $request->fresh()?->status);
    }

    public function test_AC_000_01_an_application_of_another_team_is_not_found_through_this_team(): void
    {
        Queue::fake();
        $representative = User::factory()->create();
        $mine = Team::factory()->withRepresentative()->create(['created_by' => $representative->id]);
        $other = Team::factory()->withRepresentative()->create();
        $applicant = User::factory()->create();
        $request = TeamJoinRequest::create(['team_id' => $other->id, 'user_id' => $applicant->id, 'status' => JoinRequestStatus::Pending, 'pending_user_id' => $applicant->id]);

        // 自分のチームのURLに、他チームの申請IDを入れても承認できない
        $this->actingAs($representative)->postJson("/api/teams/{$mine->id}/join-requests/{$request->id}/approve", ['roles' => ['parent']])->assertNotFound();
        $this->actingAs($representative)->postJson("/api/teams/{$mine->id}/join-requests/{$request->id}/reject")->assertNotFound();
        $this->assertSame(JoinRequestStatus::Pending, $request->fresh()?->status);
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
        'POST api/invitations/preview' => false,
        'POST api/invitations/applications' => true,
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

        // 消したルートが許可リストに残ると、次にルートを足すときの確認が抜けやすいので、残さない
        $existing = $routes->flatMap(fn (Route $route) => array_map(fn (string $method) => "{$method} {$route->uri()}", $route->methods()));
        foreach (array_keys(self::ROUTES_OUTSIDE_TEAMS) as $label) {
            $this->assertContains($label, $existing, "{$label} is in the allowlist but no longer exists");
        }
    }
}
