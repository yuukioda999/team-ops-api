<?php

namespace Tests\Feature\Auth;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * AC-001-05:IPごとの回数制限は、プロキシ(Vite・nginx)の後ろでも利用者ごとに数える。
 */
class ClientIpRateLimitTest extends AuthTestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function requestFrom(string $clientIp, int $n): int
    {
        return $this->withHeader('X-Forwarded-For', $clientIp)
            ->postJson('/api/auth/login-links', ['email' => "user{$n}@example.invalid"])
            ->getStatusCode();
    }

    public function test_AC_001_05_clients_behind_a_trusted_proxy_are_counted_separately(): void
    {
        TrustProxies::at(['127.0.0.1']); // テストのリクエストは 127.0.0.1 から届く

        foreach (range(1, 30) as $n) {
            $this->assertSame(202, $this->requestFrom('203.0.113.10', $n));
        }

        $this->assertSame(429, $this->requestFrom('203.0.113.10', 31), 'The same client hits the per-IP limit');
        $this->assertSame(202, $this->requestFrom('198.51.100.20', 32), 'Another client behind the same proxy is not blocked');
    }

    public function test_AC_001_05_a_forged_forwarded_header_from_an_untrusted_address_is_ignored(): void
    {
        TrustProxies::at([]); // どこも信頼しない

        foreach (range(1, 30) as $n) {
            $this->requestFrom('203.0.113.'.$n, $n); // 毎回違うIPを名乗っても
        }

        $this->assertSame(429, $this->requestFrom('203.0.113.99', 31), 'The forged header must not reset the limit');
    }
}
