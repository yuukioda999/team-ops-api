<?php

namespace Tests\Feature\Auth;

use App\Models\LoginToken;
use App\Models\OutboxMessage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AC-001-19:ログイン用リンクの要求に付けた戻り先を、リンクでログインした応答で返す(ADR-0010)。
 * メールの URL には入れないので、メールをどのアプリで開いても戻れる。
 */
class ReturnToTest extends AuthTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function acceptedPaths(): array
    {
        return [
            'invitation' => ['/join?token='.str_repeat('a1', 32)],
            'email change' => ['/settings/email/verify?token='.str_repeat('b2', 32)],
        ];
    }

    #[DataProvider('acceptedPaths')]
    public function test_AC_001_19_the_return_path_comes_back_after_logging_in(string $returnTo): void
    {
        $this->postJson('/api/auth/login-links', ['email' => 'parent@example.invalid', 'return_to' => $returnTo])->assertAccepted();
        $token = $this->tokenFromLatestMail('/login/verify');

        // メールの URL は短いまま。戻り先(招待リンクのトークンを含む)を入れない
        $mailUrl = OutboxMessage::latest('id')->first()?->payload['message']['url'] ?? null;
        $this->assertIsString($mailUrl);
        $this->assertStringNotContainsString('join', $mailUrl);
        // 保存も暗号化
        $this->assertStringNotContainsString($returnTo, (string) LoginToken::latest('id')->first()?->getRawOriginal('return_to'));

        $this->postJson('/api/auth/login-links/consume', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('return_to', $returnTo)
            ->assertJsonPath('needs_display_name', true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedPaths(): array
    {
        return [
            'another site' => ['https://evil.example/join?token='.str_repeat('a1', 32)],
            'protocol relative' => ['//evil.example/join?token='.str_repeat('a1', 32)],
            'another page' => ['/teams/new'],
            'extra parameter' => ['/join?token='.str_repeat('a1', 32).'&next=https://evil.example'],
            'short token' => ['/join?token=abc'],
            'script' => ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('refusedPaths')]
    public function test_AC_001_19_other_return_paths_are_ignored_and_login_still_works(string $returnTo): void
    {
        $this->postJson('/api/auth/login-links', ['email' => 'parent@example.invalid', 'return_to' => $returnTo])->assertAccepted();
        $token = $this->tokenFromLatestMail('/login/verify');

        $this->postJson('/api/auth/login-links/consume', ['token' => $token])->assertOk()->assertJsonPath('return_to', null);
    }

    public function test_AC_001_19_without_a_return_path_the_response_says_null(): void
    {
        $token = $this->requestLinkAndGetToken('parent@example.invalid');

        $this->postJson('/api/auth/login-links/consume', ['token' => $token])->assertOk()->assertJsonPath('return_to', null);
    }
}
