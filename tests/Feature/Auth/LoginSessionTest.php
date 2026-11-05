<?php

namespace Tests\Feature\Auth;

use App\Models\User;

/**
 * 001 リンクの使用とセッション(AC-001-03・04・06・07)。
 */
class LoginSessionTest extends AuthTestCase
{
    public function test_AC_001_03_an_unregistered_address_gets_an_account_and_goes_to_the_display_name_step(): void
    {
        $token = $this->requestLinkAndGetToken('new@example.invalid');

        $response = $this->postJson('/api/auth/login-links/consume', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('email', 'new@example.invalid')
            ->assertJsonPath('needs_display_name', true)
            ->assertJsonPath('teams', []);

        $this->assertTrue(User::whereEmail('new@example.invalid')->exists());
        $this->keepSession($response);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('email', 'new@example.invalid');
    }

    public function test_AC_001_03_a_registered_address_logs_in_to_the_existing_account(): void
    {
        User::factory()->create(['email' => 'coach@example.invalid', 'display_name' => '山田']);
        $token = $this->requestLinkAndGetToken('coach@example.invalid');

        $this->postJson('/api/auth/login-links/consume', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('display_name', '山田')
            ->assertJsonPath('needs_display_name', false);

        $this->assertSame(1, User::count());
    }

    public function test_AC_001_03_the_link_works_until_15_minutes(): void
    {
        $token = $this->requestLinkAndGetToken('coach@example.invalid');

        $this->travel(14)->minutes();

        $this->postJson('/api/auth/login-links/consume', ['token' => $token])->assertOk();
    }

    public function test_AC_001_04_a_link_opened_after_15_minutes_is_rejected(): void
    {
        $token = $this->requestLinkAndGetToken('coach@example.invalid');

        $this->travel(15)->minutes();
        $this->travel(1)->seconds();

        $this->postJson('/api/auth/login-links/consume', ['token' => $token])
            ->assertStatus(410)
            ->assertExactJson(['message' => 'リンクの有効期限が切れています']);
        $this->assertSame(0, User::count());
    }

    public function test_AC_001_04_a_link_opened_a_second_time_is_rejected(): void
    {
        $token = $this->requestLinkAndGetToken('coach@example.invalid');
        $this->postJson('/api/auth/login-links/consume', ['token' => $token])->assertOk();

        $this->postJson('/api/auth/login-links/consume', ['token' => $token])
            ->assertStatus(410)
            ->assertExactJson(['message' => 'リンクの有効期限が切れています']);
    }

    public function test_AC_001_04_an_unknown_or_malformed_token_gets_the_same_answer(): void
    {
        foreach ([str_repeat('a', 64), 'short', '../../etc/passwd'] as $token) {
            $this->postJson('/api/auth/login-links/consume', ['token' => $token])
                ->assertStatus(410)
                ->assertExactJson(['message' => 'リンクの有効期限が切れています']);
        }
    }

    public function test_AC_001_04_an_email_change_token_cannot_be_used_to_log_in(): void
    {
        $this->logIn('coach@example.invalid');
        $this->postJson('/api/me/email-changes', ['new_email' => 'new@example.invalid'])->assertAccepted();
        $token = $this->tokenFromLatestMail('/settings/email/verify');

        $this->postJson('/api/auth/login-links/consume', ['token' => $token])->assertStatus(410);
    }

    public function test_ADR_0008_the_session_id_changes_at_login(): void
    {
        $before = $this->getJson('/api/sanctum/csrf-cookie');
        $this->keepSession($before);
        $token = $this->requestLinkAndGetToken('coach@example.invalid');

        $after = $this->postJson('/api/auth/login-links/consume', ['token' => $token]);

        $name = (string) config('session.cookie');
        $this->assertNotSame($before->getCookie($name)?->getValue(), $after->getCookie($name)?->getValue());
    }

    public function test_ADR_0008_a_call_without_a_session_is_refused_before_the_link_is_used(): void
    {
        $token = $this->requestLinkAndGetToken('coach@example.invalid');

        $this->flushHeaders(); // Web と同じオリジンからでない(Sanctum がセッションを付けない)
        $this->postJson('/api/auth/login-links/consume', ['token' => $token])->assertStatus(419);

        $this->withHeaders(['Origin' => 'http://localhost:5173']);
        $this->postJson('/api/auth/login-links/consume', ['token' => $token])->assertOk();
    }

    public function test_AC_001_03_two_links_for_the_same_new_address_create_one_account(): void
    {
        $first = $this->requestLinkAndGetToken('new@example.invalid');
        $second = $this->requestLinkAndGetToken('new@example.invalid');

        $this->postJson('/api/auth/login-links/consume', ['token' => $first])->assertOk();
        $this->postJson('/api/auth/login-links/consume', ['token' => $second])->assertOk();

        $this->assertSame(1, User::count());
    }

    public function test_AC_001_06_logging_out_ends_the_session(): void
    {
        $this->logIn('coach@example.invalid');
        $this->getJson('/api/me')->assertOk();

        $this->postJson('/api/auth/logout')->assertNoContent();

        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_AC_001_07_the_session_expires_30_days_after_the_last_activity(): void
    {
        $this->logIn('coach@example.invalid');

        $this->travel(29)->days();
        $this->getJson('/api/me')->assertOk(); // using the app extends the session

        $this->travel(29)->days();
        $this->getJson('/api/me')->assertOk();

        $this->travel(30)->days();
        $this->travel(1)->minutes();
        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'ログインしてください']);
    }

    public function test_AC_000_02_me_requires_login(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->patchJson('/api/me', ['display_name' => '山田'])->assertUnauthorized();
        $this->postJson('/api/me/email-changes', ['new_email' => 'a@example.invalid'])->assertUnauthorized();
    }
}
