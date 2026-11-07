<?php

namespace Tests\Contract;

use App\Enums\Grade;
use App\Enums\TeamRole;
use App\Models\OutboxMessage;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Database\Factories\TeamFactory;
use Illuminate\Http\UploadedFile;
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

    public function test_player_responses_match_the_contract(): void
    {
        $this->logIn('rep@example.invalid');
        $teamId = (string) $this->postJson('/api/teams', ['name' => 'つくばFC', 'sport' => 'soccer'])->json('id');
        $team = Team::findOrFail($teamId);
        $parent = User::factory()->create(['display_name' => '山田 母']);
        $membership = TeamFactory::addMember($team, $parent, [TeamRole::Parent]);
        $base = "/api/teams/{$teamId}";

        $this->check('GET', '/api/teams/{team}', $this->getJson($base));
        $created = $this->postJson("{$base}/players", ['display_name' => '山田 太郎', 'grade' => 'e3', 'number' => 10]);
        $this->check('POST', '/api/teams/{team}/players', $created);
        $this->check('POST', '/api/teams/{team}/players', $this->postJson("{$base}/players", ['display_name' => '山田 太郎', 'grade' => 'e3']));
        $this->check('POST', '/api/teams/{team}/players', $this->postJson("{$base}/players", ['display_name' => '', 'grade' => 'e9']));
        $id = (int) $created->json('player.id');

        $this->check('GET', '/api/teams/{team}/guardian-candidates', $this->getJson("{$base}/guardian-candidates"));
        $this->check('PUT', '/api/teams/{team}/players/{player}/guardians', $this->putJson("{$base}/players/{$id}/guardians", ['membership_ids' => [$membership->id]]));
        $this->check('PUT', '/api/teams/{team}/players/{player}/guardians', $this->putJson("{$base}/players/{$id}/guardians", ['membership_ids' => [999999]]));
        $this->check('GET', '/api/teams/{team}/players', $this->getJson("{$base}/players"));
        $this->check('GET', '/api/teams/{team}/players', $this->getJson("{$base}/players?grade=e3&number=10"));
        $this->check('GET', '/api/teams/{team}/players', $this->getJson("{$base}/players?grade=x"));
        $this->check('GET', '/api/teams/{team}/players/{player}', $this->getJson("{$base}/players/{$id}"));
        $this->check('GET', '/api/teams/{team}/players/{player}', $this->getJson("{$base}/players/999999"));
        $this->check('PATCH', '/api/teams/{team}/players/{player}', $this->patchJson("{$base}/players/{$id}", ['number' => null]));
        $this->check('PATCH', '/api/teams/{team}/players/{player}', $this->patchJson("{$base}/players/{$id}", ['grade' => 'e9']));

        $this->check('GET', '/api/teams/{team}/players/import/template', $this->get("{$base}/players/import/template"));
        $csv = "表示名,学年,背番号\n佐藤 次郎,小1,\n山田 太郎,小3,\n鈴木,小7,\n";
        $this->check('POST', '/api/teams/{team}/players/import/preview', $this->post("{$base}/players/import/preview", ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv)], ['Accept' => 'application/json']));
        $this->check('POST', '/api/teams/{team}/players/import/preview', $this->post("{$base}/players/import/preview", ['file' => UploadedFile::fake()->createWithContent('p.csv', "名前\nA\n")], ['Accept' => 'application/json']));
        $this->check('POST', '/api/teams/{team}/players/import', $this->post("{$base}/players/import", ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv), 'file_hash' => hash('sha256', $csv)], ['Accept' => 'application/json']));
        $good = "表示名,学年\n佐藤 次郎,小1\n";
        $this->check('POST', '/api/teams/{team}/players/import', $this->post("{$base}/players/import", ['file' => UploadedFile::fake()->createWithContent('p.csv', $good), 'file_hash' => hash('sha256', $good)], ['Accept' => 'application/json']));
        $this->check('DELETE', '/api/teams/{team}/players/{player}', $this->deleteJson("{$base}/players/{$id}"));
        $this->check('DELETE', '/api/teams/{team}/players/{player}', $this->deleteJson("{$base}/players/{$id}"));

        // 保護者は自分の子どもだけ。変更・取り込みは 403
        $own = Player::create(['team_id' => $teamId, 'display_name' => '山田 花子', 'grade' => Grade::E1, 'number' => null]);
        $own->guardians()->create(['membership_id' => $membership->id]);
        $this->postJson('/api/auth/logout');
        $this->actingAs($parent);
        $this->check('GET', '/api/teams/{team}', $this->getJson($base));
        $this->check('GET', '/api/teams/{team}/players', $this->getJson("{$base}/players"));
        $this->check('GET', '/api/teams/{team}/players/{player}', $this->getJson("{$base}/players/{$own->id}"));
        $this->check('POST', '/api/teams/{team}/players', $this->postJson("{$base}/players", ['display_name' => 'A', 'grade' => 'e1']));
        $this->check('PATCH', '/api/teams/{team}/players/{player}', $this->patchJson("{$base}/players/{$own->id}", ['grade' => 'e2']));
        $this->check('DELETE', '/api/teams/{team}/players/{player}', $this->deleteJson("{$base}/players/{$own->id}"));
        $this->check('PUT', '/api/teams/{team}/players/{player}/guardians', $this->putJson("{$base}/players/{$own->id}/guardians", ['membership_ids' => []]));
        $this->check('GET', '/api/teams/{team}/guardian-candidates', $this->getJson("{$base}/guardian-candidates"));
        $this->check('GET', '/api/teams/{team}/players/import/template', $this->getJson("{$base}/players/import/template"));
        $this->check('POST', '/api/teams/{team}/players/import/preview', $this->post("{$base}/players/import/preview", ['file' => UploadedFile::fake()->createWithContent('p.csv', $good)], ['Accept' => 'application/json']));
        $this->check('POST', '/api/teams/{team}/players/import', $this->post("{$base}/players/import", ['file' => UploadedFile::fake()->createWithContent('p.csv', $good), 'file_hash' => hash('sha256', $good)], ['Accept' => 'application/json']));
    }

    public function test_member_and_audit_log_responses_match_the_contract(): void
    {
        $this->logIn('rep@example.invalid');
        $teamId = (string) $this->postJson('/api/teams', ['name' => 'つくばFC', 'sport' => 'soccer'])->json('id');
        $team = Team::findOrFail($teamId);
        $parent = TeamFactory::addMember($team, User::factory()->create(['display_name' => '山田 母']), [TeamRole::Parent]);
        $officerUser = User::factory()->create(['display_name' => '佐藤 役員']);
        $officer = TeamFactory::addMember($team, $officerUser, [TeamRole::Officer]);
        $base = "/api/teams/{$teamId}";

        $this->check('PATCH', '/api/teams/{team}', $this->patchJson($base, ['name' => 'つくばFC U-12']));
        $this->check('PATCH', '/api/teams/{team}', $this->patchJson($base, ['name' => '']));
        $this->check('GET', '/api/teams/{team}/members', $this->getJson("{$base}/members"));
        $this->check('PUT', '/api/teams/{team}/members/{membership}/roles', $this->putJson("{$base}/members/{$parent->id}/roles", ['roles' => ['coach', 'parent']]));
        $this->check('PUT', '/api/teams/{team}/members/{membership}/roles', $this->putJson("{$base}/members/{$parent->id}/roles", ['roles' => []]));
        $this->check('PUT', '/api/teams/{team}/members/{membership}/roles', $this->putJson("{$base}/members/999999/roles", ['roles' => ['coach']]));
        $this->check('POST', '/api/teams/{team}/representative', $this->postJson("{$base}/representative", ['membership_id' => $parent->id]));
        $this->check('GET', '/api/teams/{team}/audit-logs', $this->getJson("{$base}/audit-logs"));
        $this->check('GET', '/api/teams/{team}/audit-logs', $this->getJson("{$base}/audit-logs?before=x"));
        $this->check('DELETE', '/api/teams/{team}/members/{membership}', $this->deleteJson("{$base}/members/{$parent->id}"));
        $this->check('DELETE', '/api/teams/{team}/members/{membership}', $this->deleteJson("{$base}/members/{$parent->id}"));
        $this->check('POST', '/api/teams/{team}/representative', $this->postJson("{$base}/representative", ['membership_id' => $officer->id]));

        // 交代のあと、元の代表は役員。代表だけの操作は 403
        $this->check('GET', '/api/teams/{team}', $this->getJson($base));
        $this->check('PATCH', '/api/teams/{team}', $this->patchJson($base, ['name' => 'x']));
        $this->check('GET', '/api/teams/{team}/audit-logs', $this->getJson("{$base}/audit-logs"));
        $this->check('PUT', '/api/teams/{team}/members/{membership}/roles', $this->putJson("{$base}/members/{$officer->id}/roles", ['roles' => ['coach']]));
        $this->check('POST', '/api/teams/{team}/representative', $this->postJson("{$base}/representative", ['membership_id' => $officer->id]));
        $this->check('DELETE', '/api/teams/{team}/members/{membership}', $this->deleteJson("{$base}/members/{$officer->id}"));
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
