<?php

namespace App\Http\Resources;

use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ログイン中の利用者自身の情報。本人だけに返すため、メールアドレスを含む。
 *
 * @property User $resource
 */
final class MeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'email' => $user->email,
            'display_name' => $user->display_name,
            // 初回ログイン直後で、表示名の入力画面に進む必要がある(AC-001-03・08)
            'needs_display_name' => ! $user->hasCompletedRegistration(),
            // どのチームにも所属していなければ空。画面は「チームを作成する」「招待から参加する」を出す(AC-001-14)
            'teams' => $user->teams()->orderBy('teams.created_at')->get()->map(function (Team $team): array {
                /** @var TeamMembership $membership */
                $membership = $team->getRelation('membership');

                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'sport' => $team->sport->value,
                    'role' => $membership->role->value,
                ];
            })->values()->all(),
        ];
    }
}
