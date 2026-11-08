<?php

namespace App\Support\Demo;

use App\Enums\Grade;

/**
 * デモチームの架空のデータ(007、ADR-0021)。ログインのたびにここからデモチームを作る。
 * 名前はすべて架空で、実在の人物・チームを指すものではない。
 */
final class DemoData
{
    public const string TEAM_NAME = 'みどり台FC U-10(デモ)';

    public const int PARENTS = 20;

    /** 代表・役員・コーチ(役割、表示名、メールアドレスの名前の部分) */
    public const array STAFF = [
        ['representative', '代表 森川 誠', 'representative'],
        ['officer', '役員 早瀬 由美', 'officer'],
        ['coach', 'コーチ 岸本 亮', 'coach1'],
        ['coach', 'コーチ 野々村 翔', 'coach2'],
    ];

    /** 保護者の表示名(1番目から順に、同じ番号の選手の保護者) */
    public const array PARENT_NAMES = [
        '青木 さくら', '石原 恵', '上田 直子', '江口 真理', '大野 智子',
        '加藤 理沙', '木下 綾', '工藤 舞', '小池 優子', '斉藤 千夏',
        '柴田 香織', '杉本 早苗', '高木 美穂', '千葉 由紀', '土屋 涼子',
        '中村 奈緒', '西田 瞳', '橋本 葵', '藤井 文香', '松尾 遥',
    ];

    /** 選手(表示名、学年、背番号)。保護者と同じ順 */
    public const array PLAYERS = [
        ['青木 陽向', Grade::E3, 3], ['石原 蒼', Grade::E3, 5], ['上田 湊', Grade::E3, 7], ['江口 樹', Grade::E3, 8],
        ['大野 悠真', Grade::E3, 9], ['加藤 大翔', Grade::E3, 10], ['木下 蓮', Grade::E3, 11],
        ['工藤 颯', Grade::E4, 2], ['小池 律', Grade::E4, 4], ['斉藤 朝陽', Grade::E4, 6], ['柴田 結翔', Grade::E4, 12],
        ['杉本 奏太', Grade::E4, 13], ['高木 陽翔', Grade::E4, 14], ['千葉 新', Grade::E4, 15],
        ['土屋 晴', Grade::E2, 16], ['中村 凪', Grade::E2, 17], ['西田 碧', Grade::E2, 18], ['橋本 怜', Grade::E2, 19],
        ['藤井 楓', Grade::E1, 20], ['松尾 柚希', Grade::E1, 21],
    ];

    /** 承認待ちの参加申請(表示名、メールアドレスの名前の部分、ひとこと) */
    public const array APPLICANTS = [
        ['山辺 美紀', 'applicant1', '小3の息子が入団予定です。よろしくお願いします。'],
        ['水原 圭', 'applicant2', '体験練習に参加した水原です。'],
    ];

    /**
     * 送信済みのお知らせ(タイトル、本文、何時間前に送ったか、既読にする保護者の数、届かなかった保護者の番号)
     */
    public const array PUBLISHED_NOTICES = [
        [
            '今週末の練習試合について',
            "土曜日は9時に第二グラウンドに集合です。\n水筒とすね当てを忘れずに持ってきてください。\n雨天の場合は朝7時までにお知らせします。",
            26, 14, [18],
        ],
        [
            '保護者会のお知らせ',
            "来月の保護者会は、第1日曜の10時から公民館で行います。\n当番表の確認をしますので、ご都合の悪い方はご連絡ください。\n詳しくは https://example.invalid/demo を見てください。",
            3, 6, [],
        ],
    ];

    /** 予約中のお知らせ(タイトル、本文、何時間後に送るか) */
    public const array SCHEDULED_NOTICE = ['来週の集合時間の変更', "来週の練習は、集合を8時半に早めます。\nグラウンドの使用時間が変わったためです。", 20];

    /** サンプル CSV の選手(名簿の CSV 取込を試すため、デモチームの選手とは別の20人) */
    public const array SAMPLE_CSV_PLAYERS = [
        ['朝倉 大和', '小5', 22], ['伊東 光', '小5', 23], ['内田 春樹', '小5', 24], ['遠藤 一輝', '小5', 25],
        ['岡田 涼太', '小5', 26], ['金子 航', '小6', 27], ['菊池 優斗', '小6', 28], ['久保 晃', '小6', 29],
        ['小松 健', '小6', 30], ['佐野 拓海', '小6', 31], ['島田 翼', '小2', 32], ['菅原 陸', '小2', 33],
        ['田村 空', '小2', 34], ['堤 海斗', '小1', 35], ['永井 瑛太', '小1', 36], ['野口 啓', '小1', 37],
        ['浜田 誠也', '未就学', ''], ['平野 歩', '未就学', ''], ['堀 太一', '小4', 38], ['宮本 匠', '小4', 39],
    ];

    /**
     * デモのメールアドレス(spec 007 の補足)。.invalid は配信できないことが規格で決まっているドメイン。
     * 名前の部分は英数字にする(日本語の名前の部分は、配信依頼の検査とメールの規格で使えないため)
     */
    public static function email(string $localPart, string $teamId): string
    {
        return "{$localPart}@".self::domain($teamId);
    }

    public static function domain(string $teamId): string
    {
        return 'demo-'.strtolower($teamId).'.invalid';
    }

    /** サンプル CSV(AC-007-16)。名簿の CSV と同じ見出し */
    public static function sampleCsv(): string
    {
        $lines = ["\u{FEFF}表示名,学年,背番号"];
        foreach (self::SAMPLE_CSV_PLAYERS as [$name, $grade, $number]) {
            $lines[] = "{$name},{$grade},{$number}";
        }

        return implode("\r\n", $lines)."\r\n";
    }
}
