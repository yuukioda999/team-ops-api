<?php

namespace Tests\Feature\Auth;

use App\Enums\LoginTokenPurpose;
use App\Models\LoginToken;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/**
 * 001 ログイン用リンクの要求(AC-001-01・02・05)。
 */
class LoginLinkTest extends AuthTestCase
{
    public function test_AC_001_01_an_unregistered_address_receives_a_login_link(): void
    {
        $this->postJson('/api/auth/login-links', ['email' => 'new@example.invalid'])
            ->assertAccepted()
            ->assertExactJson(['message' => 'メールを送信しました']);

        $mail = OutboxMessage::sole();
        $this->assertSame('login_link', $mail->kind);
        $this->assertSame('new@example.invalid', $mail->payload['recipients'][0]['email'] ?? null);
        $this->assertStringContainsString('/login/verify?token=', (string) ($mail->payload['message']['url'] ?? ''));
        $this->assertSame(0, User::count(), 'The account is created only when the link is used (AC-001-03)');
        $this->assertNull(LoginToken::sole()->user_id);
    }

    public function test_ADR_0002_the_dedupe_key_stays_unique_even_if_the_database_is_recreated(): void
    {
        $this->postJson('/api/auth/login-links', ['email' => 'coach@example.invalid'])->assertAccepted();

        $token = LoginToken::sole();
        $key = OutboxMessage::sole()->dedupe_key;
        $this->assertSame('login-link:'.$token->id.'-'.substr($token->token_hash, 0, 16), $key);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{8,128}$/', $key, 'notify-relay contract');
        $this->assertStringNotContainsString($token->token_hash, $key, 'Only a prefix of the hash is used');
    }

    public function test_AC_001_02_a_registered_address_gets_exactly_the_same_response(): void
    {
        $user = User::factory()->create(['email' => 'coach@example.invalid']);

        $registered = $this->postJson('/api/auth/login-links', ['email' => 'coach@example.invalid']);
        $unregistered = $this->postJson('/api/auth/login-links', ['email' => 'nobody@example.invalid']);

        $registered->assertAccepted();
        $this->assertSame($unregistered->getStatusCode(), $registered->getStatusCode());
        $this->assertSame($unregistered->getContent(), $registered->getContent());
        $this->assertSame(2, OutboxMessage::count(), 'Both addresses receive a mail');
        $this->assertSame($user->id, LoginToken::whereEmail('coach@example.invalid')->sole()->user_id);
    }

    public function test_AC_001_02_the_address_is_matched_regardless_of_case_and_mailed_to_the_stored_address(): void
    {
        $user = User::factory()->create(['email' => 'coach@example.invalid']);

        $this->postJson('/api/auth/login-links', ['email' => ' Coach@Example.INVALID '])->assertAccepted();

        $this->assertSame($user->id, LoginToken::sole()->user_id);
        $this->assertSame('coach@example.invalid', LoginToken::sole()->email);
        $this->assertSame('coach@example.invalid', OutboxMessage::sole()->payload['recipients'][0]['email'] ?? null);
    }

    public function test_AC_001_02_a_full_width_address_is_rejected_and_never_matched_to_an_account(): void
    {
        User::factory()->create(['email' => 'victim@example.invalid']);

        foreach (['ｖｉｃｔｉｍ@example.invalid', 'victim@ｅｘａｍｐｌｅ.invalid'] as $email) {
            $this->postJson('/api/auth/login-links', ['email' => $email])
                ->assertUnprocessable()
                ->assertJsonPath('errors.email.0', 'メールアドレスの形式が正しくありません。');
        }
        $this->assertSame(0, OutboxMessage::count());
        $this->assertSame(0, LoginToken::count());
    }

    public function test_an_unregistered_address_is_stored_and_mailed_in_lower_case(): void
    {
        $this->postJson('/api/auth/login-links', ['email' => 'New.Parent@Example.INVALID'])->assertAccepted();

        $this->assertSame('new.parent@example.invalid', LoginToken::sole()->email);
        $this->assertSame('new.parent@example.invalid', OutboxMessage::sole()->payload['recipients'][0]['email'] ?? null);
    }

    public function test_AC_001_03_the_link_expires_after_15_minutes_and_only_the_token_hash_is_stored(): void
    {
        $this->freezeTime();

        $token = $this->requestLinkAndGetToken('coach@example.invalid');

        $stored = LoginToken::sole();
        $this->assertSame(LoginTokenPurpose::Login, $stored->purpose);
        $this->assertSame(now()->addMinutes(15)->timestamp, $stored->expires_at->timestamp);
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
        $this->assertNotSame($token, $stored->token_hash);
    }

    public function test_AC_001_05_the_sixth_request_within_an_hour_sends_no_mail(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/login-links', ['email' => 'coach@example.invalid'])->assertAccepted();
        }

        $this->postJson('/api/auth/login-links', ['email' => 'coach@example.invalid'])
            ->assertTooManyRequests()
            ->assertExactJson(['message' => '時間をおいてもう一度お試しください'])
            ->assertHeader('Retry-After');

        $this->assertSame(5, OutboxMessage::count());
    }

    public function test_AC_001_05_the_limit_counts_the_address_regardless_of_case(): void
    {
        foreach (['a@example.invalid', 'A@example.invalid', 'a@EXAMPLE.invalid', ' a@example.invalid', 'A@EXAMPLE.INVALID'] as $email) {
            $this->postJson('/api/auth/login-links', ['email' => $email])->assertAccepted();
        }

        $this->postJson('/api/auth/login-links', ['email' => 'a@example.invalid'])->assertTooManyRequests();
    }

    public function test_AC_001_05_other_addresses_can_still_request_links(): void
    {
        foreach (range(1, 6) as $i) {
            $this->postJson('/api/auth/login-links', ['email' => 'coach@example.invalid']);
        }

        $this->postJson('/api/auth/login-links', ['email' => 'parent@example.invalid'])->assertAccepted();
    }

    public function test_AC_001_05_requests_are_allowed_again_after_an_hour(): void
    {
        foreach (range(1, 6) as $i) {
            $this->postJson('/api/auth/login-links', ['email' => 'coach@example.invalid']);
        }

        $this->travel(61)->minutes();

        $this->postJson('/api/auth/login-links', ['email' => 'coach@example.invalid'])->assertAccepted();
    }

    public function test_AC_000_05_an_invalid_address_returns_422(): void
    {
        $this->postJson('/api/auth/login-links', ['email' => 'not-an-address'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'メールアドレスの形式が正しくありません。');

        $this->postJson('/api/auth/login-links', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'メールアドレスを入力してください。');

        $this->assertSame(0, OutboxMessage::count());
    }

    public function test_AC_000_04_requesting_a_link_logs_neither_the_address_nor_the_token(): void
    {
        config(['logging.default' => 'single']);
        $channel = Log::channel('single');
        $this->assertInstanceOf(\Illuminate\Log\Logger::class, $channel);
        $monolog = $channel->getLogger();
        $this->assertInstanceOf(Logger::class, $monolog);
        $handler = new TestHandler;
        $monolog->pushHandler($handler);

        $token = $this->requestLinkAndGetToken('coach@example.invalid');

        $written = json_encode(array_map(fn ($r) => [$r->message, $r->context], $handler->getRecords())) ?: '';
        $this->assertStringNotContainsString('coach@example.invalid', $written);
        $this->assertStringNotContainsString($token, $written);
    }
}
