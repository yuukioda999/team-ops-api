<?php

namespace Tests\Feature\Auth;

use App\Models\LoginToken;
use App\Models\OutboxMessage;
use App\Models\User;

/**
 * 001 アカウント(AC-001-08・09・10)。
 */
class AccountTest extends AuthTestCase
{
    public function test_AC_001_08_entering_a_display_name_completes_the_registration(): void
    {
        $this->logIn('new@example.invalid');

        $this->patchJson('/api/me', ['display_name' => '  山田 太郎  '])
            ->assertOk()
            ->assertJsonPath('display_name', '山田 太郎')
            ->assertJsonPath('needs_display_name', false);

        $this->assertSame('山田 太郎', User::whereEmail('new@example.invalid')->sole()->display_name);
    }

    public function test_AC_001_08_the_display_name_must_be_1_to_30_characters(): void
    {
        $this->logIn('new@example.invalid');

        $this->patchJson('/api/me', ['display_name' => str_repeat('あ', 31)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.display_name.0', '表示名は1〜30文字で入力してください。');
        $this->patchJson('/api/me', ['display_name' => '   '])
            ->assertUnprocessable()
            ->assertJsonPath('errors.display_name.0', '表示名を入力してください。');
        $this->patchJson('/api/me', ['display_name' => str_repeat('あ', 30)])->assertOk();
    }

    public function test_AC_001_09_the_new_address_gets_a_confirmation_and_the_old_one_stays_until_then(): void
    {
        $this->logIn('old@example.invalid');

        $this->postJson('/api/me/email-changes', ['new_email' => 'new@example.invalid'])
            ->assertAccepted()
            ->assertExactJson(['message' => '確認メールを送信しました']);

        $mail = OutboxMessage::latest('id')->first();
        $this->assertSame('email_change', $mail?->kind);
        $this->assertSame('new@example.invalid', $mail?->payload['recipients'][0]['email'] ?? null);
        $this->getJson('/api/me')->assertJsonPath('email', 'old@example.invalid');
    }

    public function test_AC_001_09_AC_001_10_opening_the_link_changes_the_address_and_notifies_the_old_one(): void
    {
        $this->logIn('old@example.invalid');
        $this->postJson('/api/me/email-changes', ['new_email' => 'new@example.invalid'])->assertAccepted();
        $token = $this->tokenFromLatestMail('/settings/email/verify');

        $this->postJson('/api/me/email-changes/consume', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('email', 'new@example.invalid');

        $this->assertTrue(User::whereEmail('new@example.invalid')->exists());
        $this->assertFalse(User::whereEmail('old@example.invalid')->exists());

        $notice = OutboxMessage::latest('id')->first();
        $this->assertSame('email_changed', $notice?->kind);
        $this->assertSame('old@example.invalid', $notice?->payload['recipients'][0]['email'] ?? null);
        $this->assertStringContainsString('メールアドレスが変更されました', (string) ($notice?->payload['message']['subject'] ?? ''));
    }

    public function test_AC_001_09_the_change_link_works_on_another_device_without_logging_in(): void
    {
        $this->logIn('old@example.invalid');
        $this->postJson('/api/me/email-changes', ['new_email' => 'new@example.invalid'])->assertAccepted();
        $token = $this->tokenFromLatestMail('/settings/email/verify');

        $this->flushHeaders();
        $this->withHeaders(['Origin' => 'http://localhost:5173']);
        $this->defaultCookies = [];

        $this->postJson('/api/me/email-changes/consume', ['token' => $token])->assertOk();
    }

    public function test_AC_001_09_an_expired_or_used_change_link_is_rejected(): void
    {
        $this->logIn('old@example.invalid');
        $this->postJson('/api/me/email-changes', ['new_email' => 'new@example.invalid'])->assertAccepted();
        $token = $this->tokenFromLatestMail('/settings/email/verify');

        $this->postJson('/api/me/email-changes/consume', ['token' => $token])->assertOk();
        $this->postJson('/api/me/email-changes/consume', ['token' => $token])->assertStatus(410);

        $this->postJson('/api/me/email-changes', ['new_email' => 'third@example.invalid'])->assertAccepted();
        $expired = $this->tokenFromLatestMail('/settings/email/verify');
        $this->travel(16)->minutes();
        $this->postJson('/api/me/email-changes/consume', ['token' => $expired])->assertStatus(410);
    }

    public function test_AC_001_09_an_address_used_by_another_account_cannot_be_taken_and_this_is_not_revealed(): void
    {
        User::factory()->create(['email' => 'taken@example.invalid']);
        $this->logIn('old@example.invalid');

        $this->postJson('/api/me/email-changes', ['new_email' => 'taken@example.invalid'])
            ->assertAccepted()
            ->assertExactJson(['message' => '確認メールを送信しました']);

        $mail = OutboxMessage::latest('id')->first();
        $this->assertSame('email_change_unavailable', $mail?->kind);
        $this->assertArrayNotHasKey('url', $mail?->payload['message'] ?? [], 'No link that could complete the change');
        $this->assertNotNull(LoginToken::latest('id')->first()?->used_at);
    }

    public function test_AC_001_09_the_change_fails_if_the_address_was_taken_in_the_meantime(): void
    {
        $this->logIn('old@example.invalid');
        $this->postJson('/api/me/email-changes', ['new_email' => 'new@example.invalid'])->assertAccepted();
        $token = $this->tokenFromLatestMail('/settings/email/verify');
        User::factory()->create(['email' => 'new@example.invalid']);

        $this->postJson('/api/me/email-changes/consume', ['token' => $token])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'このメールアドレスはすでに使われているため、変更できません']);
        $this->getJson('/api/me')->assertJsonPath('email', 'old@example.invalid');
    }

    public function test_AC_000_05_an_invalid_new_address_returns_422(): void
    {
        $this->logIn('old@example.invalid');

        $this->postJson('/api/me/email-changes', ['new_email' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.new_email.0', '新しいメールアドレスの形式が正しくありません。');
    }
}
