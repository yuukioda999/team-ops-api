<?php

namespace Tests\Feature\Auth;

use App\Models\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * ログインまわりのテストの共通部分。
 * Web と同じオリジンからのリクエストとして送り、セッションの Cookie を次のリクエストに引き継ぐ。
 */
abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // notify-relay への送信ジョブは動かさない(アウトボックスの中身を確かめる)
        config(['session.driver' => 'database']);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
        $this->withCredentials(); // JSON のリクエストでも Cookie を送る(ブラウザの credentials: 'include' と同じ)
    }

    /**
     * 本番ではリクエストごとにプロセスの状態が新しくなる。テストでも同じにするため、
     * 前のリクエストで認証済みになった利用者(guard の中の記憶)と、読み込んだセッションの中身を毎回消す。
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
            // 中身はリクエストの最初にDBから読み直される
            $this->app->make('session.store')->flush();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * 応答で発行されたセッションの Cookie を、以降のリクエストで使う。
     *
     * @param  TestResponse<Response>  $response
     */
    protected function keepSession(TestResponse $response): void
    {
        $name = (string) config('session.cookie');
        $cookie = $response->getCookie($name);
        $this->assertNotNull($cookie, 'The response did not set a session cookie');
        $this->withCookie($name, (string) $cookie->getValue());
    }

    /**
     * ログイン用リンクを要求し、届くはずのメールのリンクからトークンを取り出す。
     */
    protected function requestLinkAndGetToken(string $email, string $path = '/login/verify'): string
    {
        $this->postJson('/api/auth/login-links', ['email' => $email])->assertAccepted();

        return $this->tokenFromLatestMail($path);
    }

    protected function tokenFromLatestMail(string $path): string
    {
        $url = (string) (OutboxMessage::latest('id')->first()?->payload['message']['url'] ?? '');
        $this->assertStringStartsWith('http://localhost:5173'.$path.'?token=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
        $this->assertIsString($token);

        return $token;
    }

    /**
     * リンクを要求して使い、ログインした状態にする。
     */
    protected function logIn(string $email): void
    {
        $token = $this->requestLinkAndGetToken($email);
        $response = $this->postJson('/api/auth/login-links/consume', ['token' => $token])->assertOk();
        $this->keepSession($response);
    }
}
