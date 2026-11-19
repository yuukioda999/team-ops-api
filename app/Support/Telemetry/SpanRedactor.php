<?php

namespace App\Support\Telemetry;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\StatusData;
use OpenTelemetry\SDK\Trace\StatusDataInterface;

/**
 * 送る前にスパンから個人情報を伏せる(ADR-0026、AC-010-01)。
 *
 * 自動計装が付ける属性には、URL のクエリ、相手の IP アドレス、User-Agent、例外のメッセージ
 * (SQL の値や入力を含みうる)が入る。これらは送らない。
 */
final class SpanRedactor
{
    /** 送らない属性 */
    private const array DROPPED_KEYS = [
        'url.full', 'url.query', 'url.path', 'url.fragment',
        'client.address', 'client.port', 'network.peer.address', 'network.peer.port',
        'user_agent.original', 'http.user_agent', 'http.url', 'http.target',
        'exception.message', 'enduser.id', 'user.id', 'user.email', 'user.name',
    ];

    /** この名前で始まる属性は送らない(HTTP のヘッダー、DB の値) */
    private const array DROPPED_PREFIXES = ['http.request.header.', 'http.response.header.', 'db.query.parameter.', 'enduser.', 'user.'];

    /** DB の文の属性。値が文中に直接書かれていたら伏せる */
    private const array QUERY_KEYS = ['db.query.text', 'db.statement'];

    public const string MASK = '[redacted]';

    public static function attributes(AttributesInterface $attributes): AttributesInterface
    {
        $kept = [];
        foreach ($attributes->toArray() as $key => $value) {
            if (self::isDropped($key)) {
                continue;
            }
            if (in_array($key, self::QUERY_KEYS, true) && is_string($value)) {
                $value = self::maskQueryLiterals($value);
            }
            $kept[$key] = self::maskValue($value);
        }

        return Attributes::create($kept);
    }

    /**
     * @param  list<EventInterface>  $events
     * @return list<EventInterface>
     */
    public static function events(array $events): array
    {
        return array_map(
            fn (EventInterface $e) => new Event($e->getName(), $e->getEpochNanos(), self::attributes($e->getAttributes())),
            $events,
        );
    }

    /**
     * 状態の説明は例外のメッセージがそのまま入ることがあるため、送らない。
     */
    public static function status(StatusDataInterface $status): StatusDataInterface
    {
        return $status->getDescription() === '' ? $status : StatusData::create($status->getCode());
    }

    private static function isDropped(string $key): bool
    {
        if (in_array($key, self::DROPPED_KEYS, true)) {
            return true;
        }
        foreach (self::DROPPED_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SQL の文字列のリテラル('...')と、16進のリテラルを ? に置き換える。プレースホルダーの文はそのまま。
     */
    public static function maskQueryLiterals(string $sql): string
    {
        $masked = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql);
        $masked = preg_replace('/\b0x[0-9a-fA-F]+\b/', '?', (string) $masked);

        return (string) $masked;
    }

    /**
     * 想定外の属性にメールアドレスが入っていたら伏せる(念のための網)。
     */
    private static function maskValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/u', $value) === 1 ? self::MASK : $value;
        }
        if (is_array($value)) {
            return array_map(fn ($v) => self::maskValue($v), $value);
        }

        return $value;
    }
}
