<?php

namespace App\Enums;

/**
 * 参加申請の状態(002)。pending 以外は最終状態。
 */
enum JoinRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
