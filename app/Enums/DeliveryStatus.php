<?php

namespace App\Enums;

/**
 * お知らせの宛先1人ごとのメールの状態(ADR-0019)。notify-relay の結果の状態と同じ値。
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
