<?php

namespace App\UseCases\JoinRequests;

/**
 * 参加申請の結果。
 */
enum JoinRequestOutcome: string
{
    /** 新しい申請を承認待ちにした(AC-002-06・16) */
    case Submitted = 'submitted';
    /** すでに承認待ちの申請がある。新しい申請は作らない(AC-002-09) */
    case AlreadyPending = 'already_pending';
    /** すでにメンバー(AC-002-08) */
    case AlreadyMember = 'already_member';
    /** リンクが無効(AC-002-07) */
    case InvalidLink = 'invalid_link';
    /** 24時間の申請の上限を超えた(AC-002-10) */
    case TooMany = 'too_many';
}
