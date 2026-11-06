<?php

namespace Tests\Feature\Auth;

use App\Models\LoginToken;
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

    public function test_AC_001_18_tokens_are_deleted_24_hours_after_they_expire(): void
    {
        $this->postJson('/api/auth/login-links', ['email' => 'parent@example.invalid'])->assertAccepted();
        $this->assertDatabaseCount('login_tokens', 1);

        // 期限(15分)から24時間たつまでは残す
        $this->travel(15 + 24 * 60 - 1)->minutes();
        $this->assertSame(0, Artisan::call('model:prune', ['--model' => [LoginToken::class]]));
        $this->assertDatabaseCount('login_tokens', 1);

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
