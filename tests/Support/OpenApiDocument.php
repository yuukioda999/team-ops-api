<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * openapi/openapi.yaml を読み、実際の応答が定義と一致するかを確かめる。
 * このプロジェクトで使っている JSON Schema の範囲(type・required・properties・
 * additionalProperties・enum・items・$ref・null を含む type)だけを扱う。
 */
final class OpenApiDocument
{
    /** @var array<string, mixed> */
    private array $doc;

    public function __construct(string $path)
    {
        $parsed = Yaml::parseFile($path);
        Assert::assertIsArray($parsed);
        /** @var array<string, mixed> $parsed */
        $this->doc = $parsed;
    }

    public static function load(): self
    {
        return new self(dirname(__DIR__, 2).'/openapi/openapi.yaml');
    }

    /**
     * @return list<string> "METHOD /path" の一覧
     */
    public function operations(): array
    {
        $ops = [];
        foreach ($this->array($this->doc, 'paths') as $path => $item) {
            foreach (array_keys((array) $item) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $ops[] = strtoupper($method).' '.$path;
                }
            }
        }
        sort($ops);

        return $ops;
    }

    /**
     * 応答の状態コードが定義にあり、本文がスキーマに一致することを確かめる。
     */
    public function assertResponse(string $method, string $path, int $status, mixed $body): void
    {
        $operation = $this->array($this->array($this->array($this->doc, 'paths'), $path), strtolower($method));
        $response = $this->resolve($this->array($this->array($operation, 'responses'), (string) $status));
        $schema = $response['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            Assert::assertTrue($body === null || $body === '' || $body === [], "{$method} {$path} {$status} should have no body");

            return;
        }
        $this->assertMatches((array) $schema, $body, '$');
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function assertMatches(array $schema, mixed $value, string $at): void
    {
        $schema = $this->resolve($schema);

        $types = (array) ($schema['type'] ?? []);
        if ($types !== []) {
            Assert::assertTrue($this->hasType($value, $types), "{$at}: expected ".implode('|', $types).', got '.get_debug_type($value));
        }
        if ($value === null) {
            return;
        }
        if (isset($schema['enum'])) {
            Assert::assertContains($value, (array) $schema['enum'], "{$at}: not one of the allowed values");
        }
        if (is_array($value) && in_array('object', $types, true)) {
            foreach ((array) ($schema['required'] ?? []) as $key) {
                Assert::assertArrayHasKey($key, $value, "{$at}: missing required property {$key}");
            }
            $properties = (array) ($schema['properties'] ?? []);
            foreach ($value as $key => $item) {
                if (isset($properties[$key])) {
                    $this->assertMatches((array) $properties[$key], $item, "{$at}.{$key}");
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    Assert::fail("{$at}: property {$key} is not in the contract");
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->assertMatches($schema['additionalProperties'], $item, "{$at}.{$key}");
                }
            }
        }
        if (is_array($value) && in_array('array', $types, true) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                $this->assertMatches((array) $schema['items'], $item, "{$at}[{$i}]");
            }
        }
    }

    /**
     * @param  list<string>|array<mixed>  $types
     */
    private function hasType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'null' => $value === null,
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function resolve(array $node): array
    {
        while (isset($node['$ref']) && is_string($node['$ref'])) {
            $target = $this->doc;
            foreach (explode('/', substr($node['$ref'], 2)) as $part) {
                $target = $this->array($target, $part);
            }
            $node = $target;
        }

        return $node;
    }

    /**
     * @param  array<mixed>  $from
     * @return array<string, mixed>
     */
    private function array(array $from, string $key): array
    {
        Assert::assertArrayHasKey($key, $from, "openapi.yaml has no {$key}");
        Assert::assertIsArray($from[$key]);

        /** @var array<string, mixed> */
        return $from[$key];
    }
}
