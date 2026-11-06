<?php

namespace Tests\Feature;

use App\Support\Crypto\BlindIndex;
use App\Support\Security\ProductionSettings;
use RuntimeException;
use Tests\TestCase;

/**
 * ProductionSettings の検査が、実際に暗号化・ブラインドインデックス・リクエストの経路で働くことを確かめる。
 * 根拠:ADR-0003、AC-001-05、001 最終レビューの G・F(review-t30.md)。
 */
class ProductionSettingsWiringTest extends TestCase
{
    /** 本番として動かす。鍵は、試す項目以外は本物の(公開していない)値にする */
    private function asProduction(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.previous_keys' => [],
            'team_ops.blind_index_key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);
        $this->app->forgetInstance('encrypter');
        $this->app->forgetInstance(BlindIndex::class);
    }

    public function test_ADR_0003_the_encrypter_refuses_the_published_app_key_in_production(): void
    {
        $this->asProduction();
        config(['app.key' => 'base64:'.base64_encode(ProductionSettings::PUBLISHED_LOCAL_KEYS['APP_KEY'])]);
        $this->app->forgetInstance('encrypter');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY');
        $this->app->make('encrypter');
    }

    public function test_ADR_0003_the_encrypter_refuses_the_published_app_key_in_previous_keys(): void
    {
        $this->asProduction();
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.previous_keys' => [ProductionSettings::PUBLISHED_LOCAL_KEYS['APP_KEY']],
        ]);
        $this->app->forgetInstance('encrypter');

        $this->expectException(RuntimeException::class);
        $this->app->make('encrypter');
    }

    public function test_ADR_0003_the_blind_index_refuses_the_published_key_in_production(): void
    {
        $this->asProduction();
        config(['team_ops.blind_index_key' => ProductionSettings::PUBLISHED_LOCAL_KEYS['BLIND_INDEX_KEY']]);
        $this->app->forgetInstance(BlindIndex::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BLIND_INDEX_KEY');
        $this->app->make(BlindIndex::class);
    }

    public function test_AC_001_05_requests_are_not_handled_in_production_without_trusted_proxies(): void
    {
        $this->asProduction();
        config(['team_ops.trusted_proxies' => []]);

        $this->getJson('/api/health')->assertStatus(500);

        config(['team_ops.trusted_proxies' => ['10.0.0.2']]);
        $this->getJson('/api/health')->assertOk();
    }
}
