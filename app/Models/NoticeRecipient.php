<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * お知らせの送信時点の宛先1人(ADR-0017)。006 で既読・配信状況を足す。
 *
 * @property int $id
 * @property int $notice_id
 * @property int $user_id
 */
#[Fillable(['notice_id', 'user_id'])]
class NoticeRecipient extends Model {}
