<?php

namespace Tests\Support;

use App\Enums\TeamRole;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 004 の権限表(team-ops の specs/004-roles-and-permissions/spec.md の「権限表」)を、テストのデータにしたもの(ADR-0016)。
 * spec の権限表を変えるときは、ここも同じプルリクエストの組で変える。
 *
 * 各操作に書くこと:
 * - allowed:○の役割
 * - abilities:この操作を判定する TeamPolicy のメソッド(すべてのメソッドがどこかに入っていることをテストで確かめる)
 * - call:API の呼び方
 * - ok / denied:○のときと、そうでないときの応答の確かめ方(省略すると 2xx と 403)
 */
final class PermissionMatrix
{
    /**
     * まだ API のない操作(判断1)。作るときに operations() へ移す。
     */
    public const array PENDING = [
        'お知らせの作成・送信(005)',
        'お知らせの閲覧(自分が宛先のもの)(005)',
        '既読・配信状況の確認、再通知(006)',
    ];

    /**
     * @return array<string, array{
     *     allowed: list<TeamRole>,
     *     abilities: list<string>,
     *     call: callable(PermissionContext): TestResponse<Response>,
     *     ok?: callable(TestResponse<Response>): mixed,
     *     denied?: callable(TestResponse<Response>): mixed,
     *     decide?: callable(list<TeamRole>): bool,
     * }>
     */
    public static function operations(): array
    {
        $rep = TeamRole::Representative;
        $officer = TeamRole::Officer;
        $coach = TeamRole::Coach;
        $parent = TeamRole::Parent;

        return [
            'チーム名・競技の変更' => [
                'allowed' => [$rep],
                'abilities' => ['updateTeam'],
                'call' => fn (PermissionContext $c) => $c->test->patchJson($c->url(''), ['name' => '新しいチーム名']),
            ],
            'メンバー一覧の閲覧(表示名と役割)' => [
                'allowed' => [$rep, $officer],
                'abilities' => ['viewMembers'],
                'call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/members')),
            ],
            '招待リンクの発行・無効化(002)' => [
                'allowed' => [$rep, $officer],
                'abilities' => ['manageInvitation'],
                'call' => fn (PermissionContext $c) => $c->test->postJson($c->url('/invitation')),
            ],
            '参加申請の承認・却下(002)' => [
                'allowed' => [$rep, $officer],
                'abilities' => ['reviewJoinRequests'],
                'call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/join-requests')),
            ],
            '役割の変更(役員の任命・解任を含む)' => [
                'allowed' => [$rep],
                'abilities' => ['manageRoles'],
                'call' => fn (PermissionContext $c) => $c->test->putJson($c->url("/members/{$c->targetMembershipId}/roles"), ['roles' => ['parent', 'coach']]),
            ],
            '代表の交代' => [
                'allowed' => [$rep],
                'abilities' => ['transferRepresentative'],
                'call' => fn (PermissionContext $c) => $c->test->postJson($c->url('/representative'), ['membership_id' => $c->nextRepresentativeMembershipId]),
            ],
            'メンバーの除名(相手は保護者)' => [
                'allowed' => [$rep, $officer],
                'abilities' => ['removeMembers', 'removeMember'],
                'call' => fn (PermissionContext $c) => $c->test->deleteJson($c->url("/members/{$c->targetMembershipId}")),
            ],
            '選手の登録・編集・削除・CSV取込(003)' => [
                'allowed' => [$rep, $officer],
                'abilities' => ['managePlayers'],
                'call' => fn (PermissionContext $c) => $c->test->postJson($c->url('/players'), ['display_name' => '選手', 'grade' => 'e1']),
            ],
            // 名簿の2行は同じ API。全員分を見られない役割には、403 ではなく自分に紐づく選手だけを返す(ADR-0013)
            '名簿の閲覧(全員分)(003)' => [
                'allowed' => [$rep, $officer, $coach],
                'abilities' => ['viewAllPlayers'],
                'call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/players')),
                'ok' => fn (TestResponse $r) => $r->assertOk()->assertJsonPath('scope', 'all'),
                'denied' => fn (TestResponse $r) => $r->assertOk()->assertJsonPath('scope', 'own'),
            ],
            '名簿の閲覧(自分に紐づく選手のみ)(003)' => [
                'allowed' => [$parent],
                'abilities' => ['viewPlayers'],
                'call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/players')),
                'ok' => fn (TestResponse $r) => $r->assertOk()->assertJsonPath('scope', 'own'),
                'denied' => fn (TestResponse $r) => $r->assertOk()->assertJsonPath('scope', 'all'),
                // 全員分を見られる役割も持っていれば、そちらが優先される
                'decide' => fn (array $roles) => in_array($parent, $roles, true) && array_intersect(array_map(fn (TeamRole $r) => $r->value, $roles), ['representative', 'officer', 'coach']) === [],
            ],
            '監査ログの閲覧' => [
                'allowed' => [$rep],
                'abilities' => ['viewAuditLogs'],
                'call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/audit-logs')),
            ],
        ];
    }
}
