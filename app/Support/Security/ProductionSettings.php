<?php

namespace App\Support\Security;

use RuntimeException;

/**
 * ローカルとテスト以外の環境で、危ない設定のまま動き出さないようにする。
 *
 * - team-ops の env/api.env に公開しているローカル用の鍵は、誰でも読める。本番やデモで使うと、
 *   漏れたDBの暗号化項目を復号でき、メールアドレスの照合用ハッシュも辞書攻撃で戻せてしまう(ADR-0003)。
 *   Laravel は `base64:` 付きの鍵も生の文字列の鍵も受け付けるので、デコードしたバイト列で比べる。
 * - 信頼するプロキシが空、またはすべてのアドレスだと、IPごとの回数制限(AC-001-05)が全員で1つの枠になるか、
 *   X-Forwarded-For を偽ってすり抜けられる。
 */
final class ProductionSettings
{
    /** team-ops の env/api.env に公開しているローカル用の鍵(デコードしたバイト列) */
    public const array PUBLISHED_LOCAL_KEYS = [
        'APP_KEY' => 'team-ops-local-development-key!!',
        'BLIND_INDEX_KEY' => 'team-ops-local-blind-index-key!!',
    ];

    /** すべての送信元を信じる設定。ローカル以外では使わせない */
    private const array TRUST_EVERYONE = ['*', '**', '0.0.0.0/0', '::/0'];

    public static function isLocal(string $environment): bool
    {
        return in_array($environment, ['local', 'testing'], true);
    }

    /**
     * @param  'APP_KEY'|'BLIND_INDEX_KEY'  $name
     */
    public static function refusePublishedKey(string $name, mixed $value, string $environment): void
    {
        if (! self::isLocal($environment) && is_string($value) && self::decode($value) === self::PUBLISHED_LOCAL_KEYS[$name]) {
            throw new RuntimeException("{$name} is the published local development key. Set a real key.");
        }
    }

    /**
     * APP_KEY と、復号にだけ使う古い鍵(APP_PREVIOUS_KEYS)の両方を確かめる。
     *
     * @param  array<mixed>  $previousKeys
     */
    public static function refusePublishedAppKeys(mixed $key, array $previousKeys, string $environment): void
    {
        foreach ([$key, ...$previousKeys] as $value) {
            self::refusePublishedKey('APP_KEY', $value, $environment);
        }
    }

    /**
     * @param  array<mixed>  $proxies
     */
    public static function requireTrustedProxies(array $proxies, string $environment): void
    {
        if (self::isLocal($environment)) {
            return;
        }
        if ($proxies === []) {
            throw new RuntimeException('TRUSTED_PROXIES is empty. Set the address of the nginx in front of the API, or every client shares one rate limit.');
        }
        if (array_intersect($proxies, self::TRUST_EVERYONE) !== []) {
            throw new RuntimeException('TRUSTED_PROXIES trusts every address. Set the address of the nginx in front of the API, or X-Forwarded-For can be forged.');
        }
    }

    private static function decode(string $value): string
    {
        if (str_starts_with($value, 'base64:')) {
            return (string) base64_decode(substr($value, 7), true);
        }

        return $value;
    }
}
