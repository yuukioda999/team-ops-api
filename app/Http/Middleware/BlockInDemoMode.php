<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * デモモードでは使えない操作(AC-007-03・14・15、ADR-0021)。実在の人がデモ環境に登録して個人情報を入れないように、
 * デモユーザーかどうかではなく、デモモードかどうかで止める。
 */
final class BlockInDemoMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('team_ops.demo_mode')) {
            return response()->json(['message' => 'デモ環境では使えません', 'reason' => 'demo_mode'], 403);
        }

        return $next($request);
    }
}
