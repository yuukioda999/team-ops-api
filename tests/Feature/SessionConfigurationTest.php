<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * セッションの設定(ADR-0008)。実際にセッションが切れる動きは、ログインの API(T13・T14)で確かめる。
 */
class SessionConfigurationTest extends TestCase
{
    public function test_AC_001_07_sessions_expire_30_days_after_the_last_activity(): void
    {
        $this->assertSame(60 * 24 * 30, (int) config('session.lifetime'));
        $this->assertFalse((bool) config('session.expire_on_close'));
    }

    public function test_session_cookies_cannot_be_read_by_javascript(): void
    {
        $this->assertTrue((bool) config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_the_web_dev_server_is_treated_as_a_first_party_origin(): void
    {
        $this->assertContains('localhost:5173', config('sanctum.stateful'));
    }
}
