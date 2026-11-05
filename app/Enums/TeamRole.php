<?php

namespace App\Enums;

/**
 * チーム内の役割。001 では代表だけを使う。残りは 004 で追加する。
 */
enum TeamRole: string
{
    case Representative = 'representative';
}
