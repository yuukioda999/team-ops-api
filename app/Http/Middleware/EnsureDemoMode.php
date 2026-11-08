<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * デモモードのときだけ通す。それ以外は存在しない URL と同じ 404(AC-007-17)。
 */
final class EnsureDemoMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('team_ops.demo_mode')) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
