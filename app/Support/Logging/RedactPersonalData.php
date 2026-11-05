<?php

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * ログに個人情報を出さないための加工処理(AC-000-04)。
 *
 * - 個人情報やトークンを表す項目名の値は、中身を問わず伏せる
 * - メッセージや値の中のメールアドレスは伏せる
 * - 例外は、引数やリクエストの中身を含まない形(クラス・メッセージ・発生箇所)に変える
 */
final class RedactPersonalData implements ProcessorInterface
{
    public const string MASK = '[redacted]';

    private const string EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u';

    /** @var list<string> */
    private const array SENSITIVE_KEYS = [
        'email',
        'new_email',
        'old_email',
        'display_name',
        'name',
        'token',
        'password',
        'payload',
        'recipients',
        'to',
        'body',
        'subject',
        'authorization',
        'cookie',
        'x-xsrf-token',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::redactString($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    public static function redactString(string $value): string
    {
        return (string) preg_replace(self::EMAIL_PATTERN, self::MASK, $value);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function redactArray(array $values, int $depth = 0): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $result[$key] = self::MASK;

                continue;
            }
            $result[$key] = $this->redactValue($value, $depth);
        }

        return $result;
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        if ($depth > 5) {
            return self::MASK;
        }

        return match (true) {
            is_string($value) => self::redactString($value),
            is_array($value) => $this->redactArray($value, $depth + 1),
            $value instanceof Throwable => $this->summarizeThrowable($value),
            is_object($value) => get_class($value),
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeThrowable(Throwable $e): array
    {
        $summary = [
            'class' => get_class($e),
            'message' => self::redactString($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile().':'.$e->getLine(),
        ];

        $previous = $e->getPrevious();
        if ($previous !== null) {
            $summary['previous'] = [
                'class' => get_class($previous),
                'message' => self::redactString($previous->getMessage()),
            ];
        }

        return $summary;
    }
}
