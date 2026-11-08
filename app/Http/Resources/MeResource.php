<?php

namespace App\Http\Resources;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\UseCases\JoinRequests\ListMyJoinRequests;
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
            // お知らせのメールがアドレスの問題で届かなかった。アドレスを変えるまでメールを送らない(AC-006-16・17、ADR-0020)
            'email_undeliverable' => $user->email_undeliverable_at !== null,
            // デモユーザーなら、属するデモチーム(007)。画面はデモの表示と役割の切り替えを出す
            'demo_team_id' => $user->demo_team_id,
            // どのチームにも所属していなければ空。画面は「チームを作成する」「招待から参加する」を出す(AC-001-14)
            'teams' => $user->teams()->orderBy('teams.created_at')->get()->map(function (Team $team): array {
                /** @var TeamMembership $membership */
                $membership = $team->getRelation('membership');

                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'sport' => $team->sport->value,
                    'roles' => array_map(fn (TeamRole $role) => $role->value, $membership->roleList()),
                ];
            })->values()->all(),
            // 自分の参加申請。承認待ちと、承認されなかったもの(却下・自動取り消し)。承認されたものは teams に入る(AC-002-09・13)
            'join_requests' => app(ListMyJoinRequests::class)($user)
                ->flatMap(fn (TeamJoinRequest $request): array => $request->team === null ? [] : [[
                    'id' => $request->id,
                    'team_name' => $request->team->name,
                    'status' => $request->status->value,
                    'created_at' => $request->created_at->toIso8601String(),
                ]])->values()->all(),
        ];
    }
}
