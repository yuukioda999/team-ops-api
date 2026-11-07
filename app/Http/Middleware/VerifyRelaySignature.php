<?php

namespace App\Http\Middleware;

use App\Support\NotifyRelay\RelayClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * notify-relay からの webhook の署名を確かめる(notify-relay api/openapi.yaml、ADR-0019)。
 * notify-relay は 401 だけを送り直さない(鍵の設定ミスとしてアラート、AC-R001-20)ので、
 * 401 は鍵IDか署名が違うときだけにする。時刻のずれ・本文の大きさは 400・413、こちらの鍵の設定漏れは 503 にして、
 * notify-relay に送り直させる(レビュー S3)。
 */
final class VerifyRelaySignature
{
    private const int MAX_SKEW_SECONDS = 300;

    private const int MAX_BODY_BYTES = 1_048_576;

    public function handle(Request $request, Closure $next): Response
    {
        $config = config('team_ops.notify_relay');
        $keyId = is_array($config) && is_string($config['key_id'] ?? null) ? $config['key_id'] : '';
        $secret = is_array($config) && is_string($config['secret'] ?? null) ? base64_decode($config['secret'], true) : false;
        if ($keyId === '' || ! is_string($secret) || strlen($secret) < 32) {
            return response()->json(['message' => '受け取る準備ができていません'], 503);
        }

        $timestamp = $request->header('X-Relay-Timestamp');
        if (! is_string($timestamp) || preg_match('/^[0-9]{1,12}$/', $timestamp) !== 1 || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return response()->json(['message' => '時刻が正しくありません'], 400);
        }
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response()->json(['message' => '本文が大きすぎます'], 413);
        }

        $signature = $request->header('X-Relay-Signature');
        $valid = $request->header('X-Relay-Key-Id') === $keyId
            && is_string($signature)
            && hash_equals(RelayClient::sign($secret, (int) $timestamp, $request->getMethod(), $request->getPathInfo(), $body), $signature);
        if (! $valid) {
            return response()->json(['message' => '署名が正しくありません'], 401);
        }

        return $next($request);
    }
}
