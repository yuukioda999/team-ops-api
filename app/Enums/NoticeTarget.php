<?php

namespace App\Enums;

/**
 * お知らせの宛先の指定(005)。学年と役割は同時に指定できない。
 */
enum NoticeTarget: string
{
    case All = 'all';
    case Grades = 'grades';
    case Roles = 'roles';
}
