<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 信頼するプロキシからは、利用者のIP(X-Forwarded-For)と https かどうか(X-Forwarded-Proto)だけを受け取る。
 * ホスト名は受け取らない。利用者が付けた X-Forwarded-Host で、将来 url() が作るリンクを書き換えられないように。
 *
 * IP を受け取るのは AC-001-05(IPごとの回数制限)のため。ホスト名などを受け取らないのは受け入れ基準ではなく、
 * 001 最終レビューの H(review-t30.md)の判断による。
 */
class TrustedProxyHeadersTest extends TestCase
{
    public function test_AC_001_05_and_review_H_only_the_client_ip_and_scheme_are_taken_from_a_trusted_proxy(): void
    {
        TrustProxies::at(['10.0.0.2']);
        Route::get('/__proxy-probe', fn (Request $request) => [
            'ip' => $request->ip(),
            'host' => $request->getHost(),
            'secure' => $request->isSecure(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->getJson('/__proxy-probe', [
                'X-Forwarded-For' => '203.0.113.7',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'attacker.example',
            ])
            ->assertExactJson(['ip' => '203.0.113.7', 'host' => 'localhost', 'secure' => true]);
    }
}
