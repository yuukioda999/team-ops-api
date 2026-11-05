<?php

namespace App\Support\Crypto;

use Normalizer;
use RuntimeException;

/**
 * 暗号化した項目を照合するためのハッシュ値(ブラインドインデックス)を作る。
 * 値そのものは保存せず、専用の鍵で HMAC-SHA256 をとる(ADR-0003)。
 */
final class BlindIndex
{
    public function __construct(private readonly string $key)
    {
        if (strlen($key) < 32) {
            throw new RuntimeException('BLIND_INDEX_KEY must be at least 32 bytes.');
        }
    }

    public static function fromConfig(mixed $configured): self
    {
        if (! is_string($configured) || $configured === '') {
            throw new RuntimeException('BLIND_INDEX_KEY is not set.');
        }

        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            if ($decoded === false) {
                throw new RuntimeException('BLIND_INDEX_KEY is not valid base64.');
            }
            $configured = $decoded;
        }

        return new self($configured);
    }

    public function email(string $email): string
    {
        return hash_hmac('sha256', 'email:'.self::normalizeEmail($email), $this->key);
    }

    /**
     * 同じアドレスの書き方の違い(全角・大文字・前後の空白)をそろえる。
     */
    public static function normalizeEmail(string $email): string
    {
        $normalized = Normalizer::normalize($email, Normalizer::FORM_KC);

        return mb_strtolower(trim($normalized === false ? $email : $normalized));
    }
}
