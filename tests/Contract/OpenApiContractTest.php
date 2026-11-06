<?php

namespace Tests\Contract;

use App\Models\OutboxMessage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Auth\AuthTestCase;
use Tests\Support\OpenApiDocument;

/**
 * API の応答と openapi/openapi.yaml が一致していることを確かめる(Web の型はこのファイルから作るため)。
 */
class OpenApiContractTest extends AuthTestCase
{
    private OpenApiDocument $openApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openApi = OpenApiDocument::load();
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function check(string $method, string $path, TestResponse $response): void
    {
        $content = (string) $response->getContent();
        $this->openApi->assertResponse($method, $path, $response->getStatusCode(), $content === '' ? null : json_decode($content, true));
    }

    public function test_every_api_route_is_documented_and_every_documented_operation_exists(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/') && $r->uri() !== 'api/health')
            ->flatMap(fn (Route $r) => collect($r->methods())
                ->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))
                ->map(fn ($m) => $m.' /'.rtrim($r->uri(), '/')))
            ->sort()->values()->all();

        $this->assertSame($this->openApi->operations(), $routes);
    }

    public function test_auth_responses_match_the_contract(): void
    {
        $this->check('GET', '/api/sanctum/csrf-cookie', $this->getJson('/api/sanctum/csrf-cookie'));

        $this->check('POST', '/api/auth/login-links', $this->postJson('/api/auth/login-links', ['email' => 'coach@example.invalid']));
        $this->check('POST', '/api/auth/login-links', $this->postJson('/api/auth/login-links', ['email' => 'nope']));
        $url = (string) (OutboxMessage::latest('id')->first()?->payload['message']['url'] ?? '');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = is_string($query['token'] ?? null) ? $query['token'] : '';

        $this->check('GET', '/api/me', $this->getJson('/api/me'));
        $consumed = $this->postJson('/api/auth/login-links/consume', ['token' => $token]);
        $this->check('POST', '/api/auth/login-links/consume', $consumed);
        $this->keepSession($consumed);
        $this->check('POST', '/api/auth/login-links/consume', $this->postJson('/api/auth/login-links/consume', ['token' => $token]));

        $this->check('GET', '/api/me', $this->getJson('/api/me'));
        $this->check('PATCH', '/api/me', $this->patchJson('/api/me', ['display_name' => '山田']));
        $this->check('PATCH', '/api/me', $this->patchJson('/api/me', ['display_name' => '']));

        $this->check('POST', '/api/me/email-changes', $this->postJson('/api/me/email-changes', ['new_email' => 'new@example.invalid']));
        $changeToken = $this->tokenFromLatestMail('/settings/email/verify');
        $this->check('POST', '/api/me/email-changes/consume', $this->postJson('/api/me/email-changes/consume', ['token' => $changeToken]));
        $this->check('POST', '/api/me/email-changes/consume', $this->postJson('/api/me/email-changes/consume', ['token' => $changeToken]));

        $this->check('POST', '/api/auth/logout', $this->postJson('/api/auth/logout'));
        $this->check('POST', '/api/auth/logout', $this->postJson('/api/auth/logout'));
    }

    public function test_team_responses_match_the_contract(): void
    {
        $this->logIn('coach@example.invalid');

        $created = $this->postJson('/api/teams', ['name' => 'つくばFC', 'sport' => 'soccer']);
        $this->check('POST', '/api/teams', $created);
        $this->check('POST', '/api/teams', $this->postJson('/api/teams', ['name' => '', 'sport' => 'golf']));
        $this->check('GET', '/api/me', $this->getJson('/api/me'));

        $id = (string) $created->json('id');
        $this->check('GET', '/api/teams/{team}', $this->getJson("/api/teams/{$id}"));

        $other = Team::factory()->withRepresentative()->create(['created_by' => User::factory()->create()->id]);
        $this->check('GET', '/api/teams/{team}', $this->getJson("/api/teams/{$other->id}"));
    }

    public function test_invitation_responses_match_the_contract(): void
    {
        $this->logIn('rep@example.invalid');
        $teamId = (string) $this->postJson('/api/teams', ['name' => 'つくばFC', 'sport' => 'soccer'])->json('id');

        $this->check('GET', '/api/teams/{team}/invitation', $this->getJson("/api/teams/{$teamId}/invitation"));
        $issued = $this->postJson("/api/teams/{$teamId}/invitation");
        $this->check('POST', '/api/teams/{team}/invitation', $issued);
        $this->check('GET', '/api/teams/{team}/invitation', $this->getJson("/api/teams/{$teamId}/invitation"));
        $issuedUrl = $issued->json('invitation.url');
        $this->assertIsString($issuedUrl);
        parse_str((string) parse_url($issuedUrl, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
        $this->assertIsString($token);

        $this->check('POST', '/api/invitations/preview', $this->postJson('/api/invitations/preview', ['token' => $token]));
        $this->check('POST', '/api/invitations/preview', $this->postJson('/api/invitations/preview', ['token' => str_repeat('a', 64)]));
        $this->check('POST', '/api/invitations/applications', $this->postJson('/api/invitations/applications', ['token' => $token, 'display_name' => '代表']));
        $this->check('GET', '/api/teams/{team}/join-requests', $this->getJson("/api/teams/{$teamId}/join-requests"));

        // 別の人として申請する
        $this->postJson('/api/auth/logout');
        $this->logIn('parent@example.invalid');
        $this->check('POST', '/api/invitations/preview', $this->postJson('/api/invitations/preview', ['token' => $token]));
        $this->check('POST', '/api/invitations/applications', $this->postJson('/api/invitations/applications', ['token' => $token, 'display_name' => '山田', 'message' => '3年の母です']));
        $this->check('POST', '/api/invitations/applications', $this->postJson('/api/invitations/applications', ['token' => $token, 'display_name' => '山田']));
        $this->check('POST', '/api/invitations/applications', $this->postJson('/api/invitations/applications', ['token' => str_repeat('a', 64), 'display_name' => '山田']));
        $this->check('POST', '/api/invitations/applications', $this->postJson('/api/invitations/applications', ['token' => $token, 'display_name' => '']));
        $this->check('GET', '/api/me', $this->getJson('/api/me'));
        $this->check('GET', '/api/teams/{team}/join-requests', $this->getJson("/api/teams/{$teamId}/join-requests"));

        $this->postJson('/api/auth/logout');
        $this->logIn('rep@example.invalid');
        $list = $this->getJson("/api/teams/{$teamId}/join-requests");
        $this->check('GET', '/api/teams/{team}/join-requests', $list);
        $requestId = (int) $list->json('join_requests.0.id');
        $this->check('POST', '/api/teams/{team}/join-requests/{joinRequest}/approve', $this->postJson("/api/teams/{$teamId}/join-requests/{$requestId}/approve", ['roles' => ['officer']]));
        $this->check('POST', '/api/teams/{team}/join-requests/{joinRequest}/approve', $this->postJson("/api/teams/{$teamId}/join-requests/{$requestId}/approve", ['roles' => ['parent', 'coach']]));
        $this->check('POST', '/api/teams/{team}/join-requests/{joinRequest}/reject', $this->postJson("/api/teams/{$teamId}/join-requests/{$requestId}/reject"));
        $this->check('DELETE', '/api/teams/{team}/invitation', $this->deleteJson("/api/teams/{$teamId}/invitation"));

        // 承認されたメンバー(保護者・コーチ)には権限がない
        $this->postJson('/api/auth/logout');
        $this->logIn('parent@example.invalid');
        $this->check('GET', '/api/teams/{team}', $this->getJson("/api/teams/{$teamId}"));
        $this->check('GET', '/api/teams/{team}/invitation', $this->getJson("/api/teams/{$teamId}/invitation"));
        $this->check('POST', '/api/teams/{team}/invitation', $this->postJson("/api/teams/{$teamId}/invitation"));
        $this->check('DELETE', '/api/teams/{team}/invitation', $this->deleteJson("/api/teams/{$teamId}/invitation"));
        $this->check('GET', '/api/teams/{team}/join-requests', $this->getJson("/api/teams/{$teamId}/join-requests"));
        $this->check('POST', '/api/teams/{team}/join-requests/{joinRequest}/reject', $this->postJson("/api/teams/{$teamId}/join-requests/{$requestId}/reject"));
    }

    public function test_rate_limited_token_endpoints_match_the_contract(): void
    {
        $this->logIn('coach@example.invalid');
        foreach (['/api/auth/login-links/consume', '/api/me/email-changes/consume'] as $path) {
            foreach (range(1, 30) as $i) {
                $this->postJson($path, ['token' => str_repeat('a', 64)]);
            }
            $response = $this->postJson($path, ['token' => str_repeat('a', 64)]);
            $this->assertSame(429, $response->getStatusCode(), $path);
            $this->check('POST', $path, $response);
            $this->travel(2)->minutes();
        }
    }

    public function test_rate_limited_responses_match_the_contract(): void
    {
        foreach (range(1, 6) as $i) {
            $response = $this->postJson('/api/auth/login-links', ['email' => 'coach@example.invalid']);
        }
        $this->assertSame(429, $response->getStatusCode());
        $this->check('POST', '/api/auth/login-links', $response);
    }
}
