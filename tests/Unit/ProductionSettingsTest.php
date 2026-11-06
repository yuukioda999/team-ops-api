<?php

namespace Tests\Unit;

use App\Support\Security\ProductionSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 公開済みのローカル用の鍵と、空の TRUSTED_PROXIES を、ローカルとテスト以外では拒否する(ADR-0003、AC-001-05)。
 */
class ProductionSettingsTest extends TestCase
{
    /**
     * @return array<string, array{'APP_KEY'|'BLIND_INDEX_KEY'}>
     */
    public static function keys(): array
    {
        return ['APP_KEY' => ['APP_KEY'], 'BLIND_INDEX_KEY' => ['BLIND_INDEX_KEY']];
    }

    /**
     * @param  'APP_KEY'|'BLIND_INDEX_KEY'  $name
     */
    #[DataProvider('keys')]
    public function test_ADR_0003_a_published_local_key_is_refused_outside_local(string $name): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($name);

        ProductionSettings::refusePublishedKey($name, ProductionSettings::PUBLISHED_LOCAL_VALUES[$name], 'production');
    }

    /**
     * @param  'APP_KEY'|'BLIND_INDEX_KEY'  $name
     */
    #[DataProvider('keys')]
    public function test_ADR_0003_a_published_local_key_is_allowed_locally_and_a_real_key_everywhere(string $name): void
    {
        ProductionSettings::refusePublishedKey($name, ProductionSettings::PUBLISHED_LOCAL_VALUES[$name], 'local');
        ProductionSettings::refusePublishedKey($name, ProductionSettings::PUBLISHED_LOCAL_VALUES[$name], 'testing');
        ProductionSettings::refusePublishedKey($name, 'base64:'.base64_encode(random_bytes(32)), 'production');

        $this->addToAssertionCount(1);
    }

    public function test_AC_001_05_empty_trusted_proxies_are_refused_outside_local(): void
    {
        ProductionSettings::requireTrustedProxies([], 'local');
        ProductionSettings::requireTrustedProxies(['172.18.0.5'], 'production');

        $this->expectException(RuntimeException::class);
        ProductionSettings::requireTrustedProxies([], 'production');
    }
}
