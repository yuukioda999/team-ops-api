<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeTarget;

/**
 * 宛先の指定(全員/学年/役割と、その値)。
 */
final readonly class NoticeAudience
{
    /**
     * @param  list<string>  $values  学年または役割の値。全員なら空
     */
    public function __construct(
        public NoticeTarget $type,
        public array $values,
    ) {}
}
