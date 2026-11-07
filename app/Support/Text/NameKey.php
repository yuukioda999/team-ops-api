<?php

namespace App\Support\Text;

use Normalizer;

/**
 * 同じ名前かを比べるための形(ADR-0013)。前後の空白を除き、全角・半角(NFKC)と大文字・小文字をそろえる。
 * 比べるためだけに使い、保存しない。
 */
final class NameKey
{
    public static function of(string $name): string
    {
        $normalized = Normalizer::normalize(SingleLineText::trim($name), Normalizer::FORM_KC);

        return mb_strtolower(SingleLineText::trim($normalized === false ? $name : $normalized));
    }
}
