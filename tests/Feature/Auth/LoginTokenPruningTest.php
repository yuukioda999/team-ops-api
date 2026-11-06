<?php

namespace Tests\Feature\Auth;

use App\Enums\LoginTokenPurpose;
use App\Models\LoginToken;
use App\Models\User;
use App\Support\Auth\OneTimeToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AC-001-18:ログイン用・メール変更用のトークンは、期限切れから24時間で行ごと消す。
 */
class LoginTokenPruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_AC_001_18_login_and_email_change_tokens_are_deleted_24_hours_after_they_expire(): void
    {
        $this->postJson('/api/auth/login-links', ['email' => 'parent@example.invalid'])->assertAccepted();
        $user = User::factory()->create(['email' => 'coach@example.invalid']);
        OneTimeToken::issue(LoginTokenPurpose::EmailChange, 'coach-new@example.invalid', $user->id);
        $this->assertSame(
            [LoginTokenPurpose::Login, LoginTokenPurpose::EmailChange],
            LoginToken::orderBy('id')->get()->map(fn (LoginToken $t) => $t->purpose)->all(),
        );

        // 期限(15分)から24時間たつまでは残す
        $this->travel(15 + 24 * 60 - 1)->minutes();
        $this->assertSame(0, Artisan::call('model:prune', ['--model' => [LoginToken::class]]));
        $this->assertDatabaseCount('login_tokens', 2);

        $this->travel(2)->minutes();
        $this->assertSame(0, Artisan::call('model:prune', ['--model' => [LoginToken::class]]));
        $this->assertDatabaseCount('login_tokens', 0);
    }

    public function test_AC_001_18_the_deletion_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'model:prune') && str_contains((string) $event->command, 'LoginToken'));

        $this->assertSame('0 0 * * *', $events->sole()->expression);
    }
}
