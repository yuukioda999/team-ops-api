<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;
use Tests\TestCase;

/**
 * API のエラー応答の形(全機能に共通する受け入れ基準)。
 * 本物の API が揃うまで、テスト専用のルートで確かめる。
 */
class ApiErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum'])->get('/api/_test/protected', fn () => ['ok' => true]);
        Route::middleware('api')->post('/api/_test/validated', function (Request $request) {
            $request->validate([
                'email' => ['required', 'string', 'email', 'max:254'],
                'display_name' => ['required', 'string', 'between:1,30'],
            ]);

            return ['ok' => true];
        });
        Route::middleware('api')->get('/api/_test/teams/{team}', fn (Team $team) => ['id' => $team->id]);
        Route::middleware('api')->get('/api/_test/hidden', function () {
            Gate::define('see-hidden', fn (?User $user) => Response::denyAsNotFound());
            Gate::authorize('see-hidden');
        });
        Route::middleware('api')->get('/api/_test/forbidden', function () {
            Gate::define('do-forbidden', fn (?User $user) => false);
            Gate::authorize('do-forbidden');
        });
        Route::middleware('api')->get('/api/_test/crash', function () {
            throw new RuntimeException('SQLSTATE failure for coach@example.invalid');
        });
    }

    public function test_AC_000_02_calling_a_protected_api_without_logging_in_returns_401(): void
    {
        $this->getJson('/api/_test/protected')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'ログインしてください']);
    }

    public function test_AC_000_02_opening_a_protected_api_without_accept_json_also_returns_401(): void
    {
        // ブラウザのアドレス欄から直接開いた場合など。ログイン画面への移動を作ろうとして500にならない
        foreach (['/api/_test/protected', '/api/me', '/api/teams/01J0000000000000000000000A/players'] as $path) {
            $this->get($path, ['Accept' => 'text/html'])
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'ログインしてください']);
        }
    }

    public function test_AC_000_02_a_bearer_token_is_not_accepted_and_returns_401(): void
    {
        $this->withHeader('Authorization', 'Bearer some-token')
            ->getJson('/api/_test/protected')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'ログインしてください']);
    }

    public function test_AC_000_01_a_denial_hidden_as_not_found_returns_the_same_404(): void
    {
        $this->getJson('/api/_test/hidden')
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりません']);
    }

    public function test_AC_000_03_a_forbidden_operation_returns_403(): void
    {
        $this->getJson('/api/_test/forbidden')
            ->assertForbidden()
            ->assertExactJson(['message' => 'この操作を行う権限がありません']);
    }

    public function test_AC_000_05_invalid_input_returns_422_with_errors_per_field(): void
    {
        $this->postJson('/api/_test/validated', ['email' => 'not-an-address', 'display_name' => str_repeat('あ', 31)])
            ->assertUnprocessable()
            ->assertJsonPath('message', '入力内容を確認してください')
            ->assertJsonPath('errors.email.0', 'メールアドレスの形式が正しくありません。')
            ->assertJsonPath('errors.display_name.0', '表示名は1〜30文字で入力してください。');
    }

    public function test_AC_000_05_missing_input_is_reported_for_each_field(): void
    {
        $this->postJson('/api/_test/validated', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'メールアドレスを入力してください。')
            ->assertJsonPath('errors.display_name.0', '表示名を入力してください。');
    }

    public function test_AC_000_01_not_found_does_not_reveal_the_model_or_id(): void
    {
        $missingId = '01JZZZZZZZZZZZZZZZZZZZZZZZ';

        $response = $this->getJson("/api/_test/teams/{$missingId}")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりません']);

        $this->assertStringNotContainsString('Team', (string) $response->getContent());
        $this->assertStringNotContainsString($missingId, (string) $response->getContent());
    }

    public function test_unknown_api_paths_return_the_same_404(): void
    {
        $this->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりません']);
    }

    public function test_AC_000_04_a_server_error_leaks_personal_data_into_neither_the_response_nor_the_log(): void
    {
        $handler = $this->captureLog();

        $response = $this->getJson('/api/_test/crash')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'サーバーでエラーが発生しました']);

        $this->assertStringNotContainsString('coach@example.invalid', (string) $response->getContent());
        $this->assertNotEmpty($handler->getRecords(), 'The error is still logged');
        $this->assertStringNotContainsString('coach@example.invalid', $this->written($handler));
    }

    public function test_internal_details_are_hidden_outside_local_even_when_debug_is_left_on(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/_test/crash')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'サーバーでエラーが発生しました']);
    }

    private function captureLog(): TestHandler
    {
        config(['logging.default' => 'single']);
        $channel = Log::channel('single');
        $this->assertInstanceOf(\Illuminate\Log\Logger::class, $channel);
        $logger = $channel->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $handler = new TestHandler;
        $logger->pushHandler($handler);

        return $handler;
    }

    private function written(TestHandler $handler): string
    {
        return json_encode(array_map(
            fn ($record) => [$record->message, $record->context, $record->extra],
            $handler->getRecords(),
        ), JSON_UNESCAPED_UNICODE) ?: '';
    }
}
