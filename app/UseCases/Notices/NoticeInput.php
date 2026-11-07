<?php

namespace App\UseCases\Notices;

use Illuminate\Support\Carbon;

/**
 * お知らせの入力。scheduledAt が null なら今すぐ送信。
 */
final readonly class NoticeInput
{
    public function __construct(
        public string $title,
        public string $body,
        public NoticeAudience $audience,
        public ?Carbon $scheduledAt,
    ) {}
}
