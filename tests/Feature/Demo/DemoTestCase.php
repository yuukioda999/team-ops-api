<?php

namespace Tests\Feature\Demo;

use App\Models\Team;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * デモモード(007)のテストの土台。デモモードを有効にし、notify-relay と Mailpit を Http::fake で置き換える。
 */
abstract class DemoTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> notify-relay に送った配信依頼 */
    protected array $relayed = [];

    /** @var list<array{ID: string, Subject: string, To: list<array{Address: string}>, Created: string, Text: string}> Mailpit にあるメール(新しい順) */
    protected array $mailbox = [];

    /** @var list<string> Mailpit に削除を頼んだ検索条件 */
    protected array $mailpitDeleted = [];

    protected function setUp(): void
    {
        parent::setUp();
        // ブラウザと同じく、セッションの Cookie でログインを続ける(AuthTestCase と同じ)
        config(['session.driver' => 'database']);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
        $this->withCredentials();
        config(['team_ops.demo_mode' => true, 'team_ops.invalid_recipients_only' => false, 'team_ops.mailpit_url' => 'http://mailpit.test:8025']);
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'http://mailpit.test:8025')) {
                return $this->mailpit($request);
            }
            $body = json_decode($request->body(), true);
            $this->relayed[] = is_array($body) ? $body : [];

            return Http::response(['delivery_id' => 'dlv_'.str_pad((string) count($this->relayed), 26, '0', STR_PAD_LEFT)], 202);
        });
    }

    private function mailpit(Request $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $q = is_string($query['query'] ?? null) ? $query['query'] : '';
        if ($request->method() === 'DELETE') {
            $this->mailpitDeleted[] = $q;

            return Http::response(null, 200);
        }
        if ($path === '/api/v1/search') {
            // to:"@ドメイン" の部分一致(Mailpit と同じく緩い一致にして、API 側の絞り込みを確かめる)
            $needle = trim(substr($q, 3), '"@');
            $hits = array_values(array_filter($this->mailbox, fn (array $m) => array_filter($m['To'], fn (array $t) => str_contains($t['Address'], $needle)) !== []));

            return Http::response(['messages' => array_map(fn (array $m) => ['ID' => $m['ID'], 'Subject' => $m['Subject'], 'To' => $m['To'], 'Created' => $m['Created']], $hits)]);
        }
        foreach ($this->mailbox as $m) {
            if ($path === '/api/v1/message/'.$m['ID']) {
                return Http::response(['Subject' => $m['Subject'], 'To' => $m['To'], 'Date' => $m['Created'], 'Text' => $m['Text']]);
            }
        }

        return Http::response(['error' => 'not found'], 404);
    }

    /**
     * 前のリクエストの認証の記憶とセッションの中身を毎回消す(本番ではリクエストごとに新しい)。AuthTestCase と同じ
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->make('auth')->forgetGuards();
        if ($this->app->resolved('session.store')) {
            $this->app->make('session.store')->flush();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * デモとしてログインし、以降のリクエストでそのセッションを使う。
     *
     * @return TestResponse<Response>
     */
    protected function demoLogin(string $role = 'representative', string $ip = '203.0.113.10'): TestResponse
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/demo/sessions', ['role' => $role]);
        $cookie = $response->getCookie((string) config('session.cookie'));
        if ($response->status() === 201 && $cookie !== null) {
            $this->withCookie((string) config('session.cookie'), (string) $cookie->getValue());
        }

        return $response;
    }

    /**
     * 次のリクエストで使うセッションの Cookie を、応答で発行されたものにする(役割の切り替えのあと)。
     *
     * @param  TestResponse<Response>  $response
     */
    protected function keepSession(TestResponse $response): void
    {
        $cookie = $response->getCookie((string) config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->withCookie((string) config('session.cookie'), (string) $cookie->getValue());
    }

    /**
     * @param  TestResponse<Response>  $login
     */
    protected function demoTeam(TestResponse $login): Team
    {
        return Team::findOrFail((string) $login->json('demo_team_id'));
    }

    /**
     * @param  TestResponse<Response>  $login
     */
    protected function demoUser(TestResponse $login): User
    {
        return User::whereEmail((string) $login->json('email'))->sole();
    }
}
