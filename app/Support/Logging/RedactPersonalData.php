<?php

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * ログに個人情報を出さないための加工処理(AC-000-04)。
 *
 * - 個人情報やトークンを表す項目名の値は、中身を問わず伏せる
 * - メッセージや値の中のメールアドレスと、URLのクエリに入ったトークンは伏せる
 * - 表示名は形で見分けられないため、項目名で伏せる。例外のメッセージに表示名を入れないこと
 * - 例外は、引数やリクエストの中身を含まない形(クラス・メッセージ・発生箇所)に変える
 */
final class RedactPersonalData implements ProcessorInterface
{
    public const string MASK = '[redacted]';

    /** メールアドレス。URLエンコードされた形(%40)も含む */
    private const string EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+(?:@|%40)[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/iu';

    /** URLのクエリに入ったトークン(ログイン用リンクなど) */
    private const string TOKEN_QUERY_PATTERN = '/([?&](?:token|signature)=)[^&\s"\']+/i';

    /**
     * 項目名にこれらを含めば、値を中身を問わず伏せる。
     * 項目名は小文字にし、_ と - を除いてから比べる(user_email、displayName、login-token など)。
     *
     * @var list<string>
     */
    private const array SENSITIVE_KEY_PARTS = [
        'email',
        'displayname',
        'token',
        'password',
        'secret',
        'authorization',
        'cookie',
        'payload',
        'recipient',
    ];

    /**
     * 短く汎用的な名前のため、完全に一致したときだけ伏せる。
     *
     * @var list<string>
     */
    private const array SENSITIVE_KEYS_EXACT = ['to', 'body', 'subject'];

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
        $value = (string) preg_replace(self::TOKEN_QUERY_PATTERN, '$1'.self::MASK, $value);

        return (string) preg_replace(self::EMAIL_PATTERN, self::MASK, $value);
    }

    public static function isSensitiveKey(string $key): bool
    {
        $normalized = str_replace(['_', '-'], '', strtolower($key));

        if (in_array($normalized, self::SENSITIVE_KEYS_EXACT, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function redactArray(array $values, int $depth = 0): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
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
