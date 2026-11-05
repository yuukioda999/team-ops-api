<?php

namespace Tests\Unit;

use App\Support\Crypto\BlindIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BlindIndexTest extends TestCase
{
    private const string KEY = 'base64:dGVzdC1ibGluZC1pbmRleC1rZXktMzItYnl0ZXMhISE=';

    /**
     * @return array<string, array{string, string}>
     */
    public static function sameAddressProvider(): array
    {
        return [
            'upper case' => ['Coach@Example.invalid', 'coach@example.invalid'],
            'surrounding spaces' => ['  coach@example.invalid ', 'coach@example.invalid'],
        ];
    }

    #[DataProvider('sameAddressProvider')]
    public function test_the_same_address_written_differently_gets_the_same_index(string $input, string $normalized): void
    {
        $index = BlindIndex::fromConfig(self::KEY);

        $this->assertSame($index->email($normalized), $index->email($input));
    }

    public function test_different_addresses_get_different_indexes(): void
    {
        $index = BlindIndex::fromConfig(self::KEY);

        $this->assertNotSame($index->email('a@example.invalid'), $index->email('b@example.invalid'));
    }

    public function test_the_index_does_not_contain_the_address(): void
    {
        $value = BlindIndex::fromConfig(self::KEY)->email('coach@example.invalid');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $value);
    }

    public function test_the_index_depends_on_the_key(): void
    {
        $other = 'base64:'.base64_encode(str_repeat('k', 32));

        $this->assertNotSame(
            BlindIndex::fromConfig(self::KEY)->email('coach@example.invalid'),
            BlindIndex::fromConfig($other)->email('coach@example.invalid'),
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidKeyProvider(): array
    {
        return [
            'not set' => [null],
            'empty' => [''],
            'too short' => ['short-key'],
            'broken base64' => ['base64:***'],
        ];
    }

    #[DataProvider('invalidKeyProvider')]
    public function test_an_unusable_key_is_rejected(mixed $key): void
    {
        $this->expectException(RuntimeException::class);

        BlindIndex::fromConfig($key);
    }
}
