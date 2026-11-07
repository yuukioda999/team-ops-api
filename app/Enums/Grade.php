<?php

namespace App\Enums;

/**
 * 選手の学年(003)。画面と CSV では「未就学」「小1」〜「中3」と書く。
 */
enum Grade: string
{
    case Preschool = 'preschool';
    case E1 = 'e1';
    case E2 = 'e2';
    case E3 = 'e3';
    case E4 = 'e4';
    case E5 = 'e5';
    case E6 = 'e6';
    case J1 = 'j1';
    case J2 = 'j2';
    case J3 = 'j3';

    public function label(): string
    {
        return match ($this) {
            self::Preschool => '未就学',
            self::E1 => '小1',
            self::E2 => '小2',
            self::E3 => '小3',
            self::E4 => '小4',
            self::E5 => '小5',
            self::E6 => '小6',
            self::J1 => '中1',
            self::J2 => '中2',
            self::J3 => '中3',
        };
    }

    /**
     * 「小3」などの書き方から探す。全角の数字(小３)も受け付ける。
     */
    public static function fromLabel(string $label): ?self
    {
        $normalized = (string) \Normalizer::normalize(trim($label), \Normalizer::FORM_KC);
        foreach (self::cases() as $grade) {
            if ($grade->label() === $normalized) {
                return $grade;
            }
        }

        return null;
    }
}
