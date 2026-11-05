<?php

namespace Tests\Feature\Outbox;

use App\Support\NotifyRelay\RelayClient;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RelayClientConfigTest extends TestCase
{
    private const string PUBLISHED_LOCAL_SECRET = 'bG9jYWwtZGV2LXJlbGF5LXNpZ25pbmcta2V5LTAwMDE=';

    /**
     * @return array<string, array{string, bool}>
     */
    public static function environments(): array
    {
        return [
            'local accepts the published local key' => ['local', true],
            'testing accepts the published local key' => ['testing', true],
            'production refuses the published local key' => ['production', false],
            'staging refuses the published local key' => ['staging', false],
        ];
    }

    #[DataProvider('environments')]
    public function test_the_published_local_signing_key_is_only_usable_locally(string $environment, bool $allowed): void
    {
        $config = ['url' => 'http://notify-relay:8080', 'key_id' => 'local', 'secret' => self::PUBLISHED_LOCAL_SECRET];

        if (! $allowed) {
            $this->expectException(RuntimeException::class);
        }
        $client = RelayClient::fromConfig(app(Factory::class), $config, $environment);

        $this->assertInstanceOf(RelayClient::class, $client);
    }

    public function test_a_missing_or_short_secret_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        RelayClient::fromConfig(app(Factory::class), ['key_id' => 'k1', 'secret' => base64_encode('short')], 'production');
    }
}
