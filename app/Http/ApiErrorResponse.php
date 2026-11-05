<?php

namespace App\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * API のエラー応答を1つの形に揃える。
 *
 * 形: { "message": "利用者向けの文言", "errors": { "項目": ["内容"] } }
 * errors は 422 のときだけ付ける(AC-000-05)。
 * 404 は、データが存在しない場合と見る権限がない場合で同じ応答にする(AC-000-01)。
 */
final class ApiErrorResponse
{
    private const array MESSAGES = [
        400 => 'リクエストの形式が正しくありません',
        401 => 'ログインしてください',
        403 => 'この操作を行う権限がありません',
        404 => '見つかりません',
        405 => 'この操作には対応していません',
        419 => 'ページの有効期限が切れました。再読み込みしてください',
        422 => '入力内容を確認してください',
        429 => '時間をおいてもう一度お試しください',
        500 => 'サーバーでエラーが発生しました',
        503 => '一時的に利用できません。時間をおいてもう一度お試しください',
    ];

    public static function from(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => self::make(422, ['errors' => $e->errors()]),
            $e instanceof AuthenticationException => self::make(401),
            $e instanceof AuthorizationException => self::make(403),
            $e instanceof RecordsNotFoundException => self::make(404),
            $e instanceof TokenMismatchException => self::make(419),
            $e instanceof ThrottleRequestsException => self::make(429, [], $e->getHeaders()),
            $e instanceof HttpExceptionInterface => self::make($e->getStatusCode(), [], $e->getHeaders()),
            // 開発中は原因を見たいので、Laravel 標準の表示(トレース付き)に任せる
            (bool) config('app.debug') => null,
            default => self::make(500),
        };
    }

    public static function message(int $status): string
    {
        return self::MESSAGES[$status] ?? self::MESSAGES[$status >= 500 ? 500 : 400];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string|list<string>|null>  $headers
     */
    private static function make(int $status, array $extra = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(['message' => self::message($status)] + $extra, $status, $headers);
    }
}
