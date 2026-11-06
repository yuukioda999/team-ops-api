<?php

namespace App\Http\Middleware;

use App\Support\Security\ProductionSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ローカルとテスト以外で TRUSTED_PROXIES が空かすべてのアドレスなら、リクエストを処理しない(AC-001-05)。
 * HTTP のリクエストだけで確かめる(キューのワーカーや Docker のビルドでは使わないため)。
 */
final class RequireTrustedProxies
{
    public function handle(Request $request, Closure $next): Response
    {
        ProductionSettings::requireTrustedProxies((array) config('team_ops.trusted_proxies'), (string) app()->environment());

        return $next($request);
    }
}
