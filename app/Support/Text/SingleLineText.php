<?php

namespace App\Support\Text;

/**
 * 表示名やチーム名のような、1行の名前の入力を整える。
 */
final class SingleLineText
{
    /** 改行・タブなどの制御文字と、文字の向きを変える書式文字(Cc・Cf) */
    public const string FORBIDDEN_PATTERN = '/[\p{Cc}\p{Cf}]/u';

    /**
     * 前後の空白を取り除く。全角スペース(U+3000)などの Unicode の空白も対象にする。
     */
    public static function trim(string $value): string
    {
        return (string) preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value);
    }
}
