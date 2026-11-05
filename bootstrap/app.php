<?php

use App\Http\ApiErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Web と同じオリジンからの API 呼び出しを Cookie のセッションで認証する(ADR-0008)
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 入力値をセッションに残さない(個人情報を含むため)
        $exceptions->dontFlash(['email', 'new_email', 'display_name', 'token']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API のエラー応答の形を揃える。例外の中身(モデル名やSQL)は返さない
        $exceptions->render(
            fn (Throwable $e, Request $request) => ApiErrorResponse::from($e, $request),
        );
    })->create();
