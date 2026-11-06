<?php

namespace Tests\Unit;

use App\Support\Security\ProductionSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 公開済みのローカル用の鍵と、空またはすべてを信じる TRUSTED_PROXIES を、ローカルとテスト以外では拒否する。
 * 根拠:ADR-0003(鍵)、AC-001-05(IPごとの回数制限)、001 最終レビューの G・F(review-t30.md)。
 */
class ProductionSettingsTest extends TestCase
{
    /**
     * @return array<string, array{'APP_KEY'|'BLIND_INDEX_KEY', string}>
     */
    public static function publishedKeys(): array
    {
        $cases = [];
        foreach (ProductionSettings::PUBLISHED_LOCAL_KEYS as $name => $bytes) {
            $cases["{$name} as base64"] = [$name, 'base64:'.base64_encode($bytes)];
            $cases["{$name} as raw text"] = [$name, $bytes];
        }

        return $cases;
    }

    /**
     * @param  'APP_KEY'|'BLIND_INDEX_KEY'  $name
     */
    #[DataProvider('publishedKeys')]
    public function test_ADR_0003_a_published_local_key_is_refused_outside_local_however_it_is_written(string $name, string $value): void
    {
        ProductionSettings::refusePublishedKey($name, $value, 'local');
        ProductionSettings::refusePublishedKey($name, $value, 'testing');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($name);
        ProductionSettings::refusePublishedKey($name, $value, 'production');
    }

    public function test_ADR_0003_a_real_key_is_allowed(): void
    {
        ProductionSettings::refusePublishedKey('APP_KEY', 'base64:'.base64_encode(random_bytes(32)), 'production');
        ProductionSettings::refusePublishedAppKeys('base64:'.base64_encode(random_bytes(32)), [], 'production');

        $this->addToAssertionCount(1);
    }

    public function test_ADR_0003_a_published_key_left_in_app_previous_keys_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        ProductionSettings::refusePublishedAppKeys(
            'base64:'.base64_encode(random_bytes(32)),
            ['base64:'.base64_encode(ProductionSettings::PUBLISHED_LOCAL_KEYS['APP_KEY'])],
            'production',
        );
    }

    /**
     * @return array<string, array{list<string>, string, bool}>
     */
    public static function proxies(): array
    {
        return [
            'empty outside local' => [[], 'production', false],
            'everyone with *' => [['*'], 'production', false],
            'everyone with **' => [['**'], 'production', false],
            'every IPv4 address' => [['10.0.0.2', '0.0.0.0/0'], 'production', false],
            'every IPv6 address' => [['::/0'], 'production', false],
            'the nginx address' => [['172.18.0.5'], 'production', true],
            'a private range' => [['172.16.0.0/12'], 'production', true],
            'empty locally' => [[], 'local', true],
            'everyone locally' => [['*'], 'local', true],
        ];
    }

    /**
     * @param  list<string>  $proxies
     */
    #[DataProvider('proxies')]
    public function test_AC_001_05_trusted_proxies_must_name_the_proxy_outside_local(array $proxies, string $environment, bool $allowed): void
    {
        if (! $allowed) {
            $this->expectException(RuntimeException::class);
        }

        ProductionSettings::requireTrustedProxies($proxies, $environment);
        $this->addToAssertionCount(1);
    }
}
