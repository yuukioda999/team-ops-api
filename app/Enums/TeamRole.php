<?php

namespace App\Enums;

/**
 * チーム内の役割(004)。1人が複数持てる。できる操作は持っている役割の和(ADR-0011)。
 */
enum TeamRole: string
{
    case Representative = 'representative';
    case Officer = 'officer';
    case Coach = 'coach';
    case Parent = 'parent';

    public function label(): string
    {
        return match ($this) {
            self::Representative => '代表',
            self::Officer => '役員',
            self::Coach => 'コーチ',
            self::Parent => '保護者',
        };
    }

    /**
     * 役割の変更で付け外しできる役割。代表は交代でしか動かない(ADR-0015)。
     *
     * @return list<self>
     */
    public static function changeable(): array
    {
        return [self::Officer, self::Coach, self::Parent];
    }

    /**
     * 参加申請の承認で付けられる役割。役員・代表の任命は 004 の役割変更で代表だけが行う(AC-002-14)。
     *
     * @return list<self>
     */
    public static function grantableOnApproval(): array
    {
        return [self::Parent, self::Coach];
    }
}
