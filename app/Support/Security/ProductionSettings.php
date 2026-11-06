<?php

namespace App\Support\Security;

use RuntimeException;

/**
 * ローカルとテスト以外の環境で、危ない設定のまま動き出さないようにする。
 *
 * - team-ops の env/api.env に公開しているローカル用の鍵は、誰でも読める。本番やデモで使うと、
 *   漏れたDBの暗号化項目を復号でき、メールアドレスの照合用ハッシュも辞書攻撃で戻せてしまう(ADR-0003)。
 * - 信頼するプロキシが空のままだと、すべての利用者が nginx のアドレスとして数えられ、
 *   IPごとの回数制限が全員で1つの枠になる(AC-001-05)。
 */
final class ProductionSettings
{
    /** team-ops の env/api.env に公開しているローカル用の値 */
    public const array PUBLISHED_LOCAL_VALUES = [
        'APP_KEY' => 'base64:dGVhbS1vcHMtbG9jYWwtZGV2ZWxvcG1lbnQta2V5ISE=',
        'BLIND_INDEX_KEY' => 'base64:dGVhbS1vcHMtbG9jYWwtYmxpbmQtaW5kZXgta2V5ISE=',
    ];

    public static function isLocal(string $environment): bool
    {
        return in_array($environment, ['local', 'testing'], true);
    }

    /**
     * @param  'APP_KEY'|'BLIND_INDEX_KEY'  $name
     */
    public static function refusePublishedKey(string $name, mixed $value, string $environment): void
    {
        if (! self::isLocal($environment) && $value === self::PUBLISHED_LOCAL_VALUES[$name]) {
            throw new RuntimeException("{$name} is the published local development key. Set a real key.");
        }
    }

    /**
     * @param  array<mixed>  $proxies
     */
    public static function requireTrustedProxies(array $proxies, string $environment): void
    {
        if (! self::isLocal($environment) && $proxies === []) {
            throw new RuntimeException('TRUSTED_PROXIES is empty. Set the address of the nginx in front of the API, or every client shares one rate limit.');
        }
    }
}
