<?php

namespace Tests\Feature;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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

    public function test_AC_000_04_server_errors_do_not_return_internal_details(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/_test/crash')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'サーバーでエラーが発生しました']);

        $this->assertStringNotContainsString('coach@example.invalid', (string) $response->getContent());
    }
}
