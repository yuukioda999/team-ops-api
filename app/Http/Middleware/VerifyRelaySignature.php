<?php

namespace App\Http\Middleware;

use App\Support\NotifyRelay\RelayClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * notify-relay からの webhook の署名を確かめる(notify-relay api/openapi.yaml、ADR-0019)。
 * 鍵ID・時刻(前後5分)・署名のどれかが違えば 401。理由は区別しない。
 * 401 を返すと notify-relay は送り直さず、鍵の設定ミスとしてアラートを出す(AC-R001-20)。
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

        $timestamp = $request->header('X-Relay-Timestamp');
        $signature = $request->header('X-Relay-Signature');
        $body = $request->getContent();
        $valid = $keyId !== '' && is_string($secret) && strlen($secret) >= 32
            && $request->header('X-Relay-Key-Id') === $keyId
            && is_string($timestamp) && preg_match('/^[0-9]{1,12}$/', $timestamp) === 1
            && abs(time() - (int) $timestamp) <= self::MAX_SKEW_SECONDS
            && is_string($signature)
            && strlen($body) <= self::MAX_BODY_BYTES
            && hash_equals(RelayClient::sign($secret, (int) $timestamp, $request->getMethod(), $request->getPathInfo(), $body), $signature);

        if (! $valid) {
            return response()->json(['message' => '署名が正しくありません'], 401);
        }

        return $next($request);
    }
}
