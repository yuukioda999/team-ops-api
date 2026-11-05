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

    public function test_rate_limited_responses_match_the_contract(): void
    {
        foreach (range(1, 6) as $i) {
            $response = $this->postJson('/api/auth/login-links', ['email' => 'coach@example.invalid']);
        }
        $this->assertSame(429, $response->getStatusCode());
        $this->check('POST', '/api/auth/login-links', $response);
    }
}
