<?php

namespace Tests\Support;

use App\Enums\TeamRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 004 の権限表(team-ops の specs/004-roles-and-permissions/spec.md の「権限表」)を、テストのデータにしたもの(ADR-0016)。
 * spec の権限表を変えるときは、ここも同じプルリクエストの組で変える。
 *
 * 各行に書くこと:
 * - allowed:○の役割
 * - abilities:この行を判定する TeamPolicy のメソッド(すべてのメソッドがどこかの行に入っていることをテストで確かめる)
 * - calls:この行に含まれる操作の API を、すべて呼ぶ。ok / denied を省くと、2xx と 403 で確かめる
 * - decide:役割の和の決まりが当てはまらない行だけ(名簿の2行、ADR-0016 の例外)
 */
final class PermissionMatrix
{
    /** spec の権限表の行(上から順) */
    public const array SPEC_ROWS = [
        'チーム名・競技の変更',
        'メンバー一覧の閲覧(表示名と役割)',
        '招待リンクの発行・無効化(002)',
        '参加申請の承認・却下(002)',
        '役割の変更(役員の任命・解任を含む)',
        '代表の交代',
        'メンバーの除名',
        '選手の登録・編集・削除・CSV取込(003)',
        '名簿の閲覧(全員分)(003)',
        '名簿の閲覧(自分に紐づく選手のみ)(003)',
        'お知らせの作成・送信(005)',
        'お知らせの閲覧(自分が宛先のもの)(005)',
        '既読・配信状況の確認、再通知(006)',
        '監査ログの閲覧',
    ];

    /**
     * まだ API のない行(判断1)。作るときに operations() へ移す。
     * AC-004-02(コーチ兼保護者がお知らせを作成できる)は、005 でこの行を移すときに確かめる。
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
     *     calls: list<array{call: callable(PermissionContext): TestResponse<Response>, ok?: callable(TestResponse<Response>): mixed, denied?: callable(TestResponse<Response>): mixed}>,
     *     decide?: callable(list<TeamRole>): bool,
     * }>
     */
    public static function operations(): array
    {
        $rep = TeamRole::Representative;
        $officer = TeamRole::Officer;
        $coach = TeamRole::Coach;
        $parent = TeamRole::Parent;
        $csv = "表示名,学年\n取込 太郎,小1\n";
        $upload = fn () => UploadedFile::fake()->createWithContent('players.csv', $csv);
        $allPlayers = [$rep, $officer, $coach];

        return [
            'チーム名・競技の変更' => [
                'allowed' => [$rep],
                'abilities' => ['updateTeam'],
                'calls' => [
                    ['call' => fn (PermissionContext $c) => $c->test->patchJson($c->url(''), ['name' => '新しいチーム名', 'sport' => 'baseball'])],
                ],
            ],
            'メンバー一覧の閲覧(表示名と役割)' => [
                'allowed' => [$rep, $officer],
                'abilities' => ['viewMembers'],
                'calls' => [
                    ['call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/members'))],
                ],
            ],
            '招待リンクの発行・無効化(002)' => [
                'allowed' => [$rep, $officer],
                'abilities' => ['manageInvitation'],
                'calls' => [
                    ['call' => fn (PermissionContext $c) => $c->test->postJson($c->url('/invitation'))],
                    ['call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/invitation'))],
                    ['call' => fn (PermissionContext $c) => $c->test->deleteJson($c->url('/invitation'))],
                ],
            ],
            '参加申請の承認・却下(002)' => [
                'allowed' => [$rep, $officer],
                'abilities' => ['reviewJoinRequests'],
                'calls' => [
                    ['call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/join-requests'))],
                    ['call' => fn (PermissionContext $c) => $c->test->postJson($c->url("/join-requests/{$c->joinRequestIds[0]}/approve"), ['roles' => ['parent']])],
                    ['call' => fn (PermissionContext $c) => $c->test->postJson($c->url("/join-requests/{$c->joinRequestIds[1]}/reject"))],
                ],
            ],
            '役割の変更(役員の任命・解任を含む)' => [
                'allowed' => [$rep],
                'abilities' => ['manageRoles'],
                'calls' => [
                    ['call' => fn (PermissionContext $c) => $c->test->putJson($c->url("/members/{$c->targetMembershipId}/roles"), ['roles' => ['parent', 'coach']])],
                ],
            ],
            '代表の交代' => [
                'allowed' => [$rep],
                'abilities' => ['transferRepresentative'],
                'calls' => [
                    ['call' => fn (PermissionContext $c) => $c->test->postJson($c->url('/representative'), ['membership_id' => $c->nextRepresentativeMembershipId])],
                ],
            ],
            // 相手は保護者。役員が代表・役員を除名できないこと(※1)は AC-004-07 のテストで確かめる
            'メンバーの除名' => [
                'allowed' => [$rep, $officer],
                'abilities' => ['removeMembers', 'removeMember'],
                'calls' => [
                    ['call' => fn (PermissionContext $c) => $c->test->deleteJson($c->url("/members/{$c->targetMembershipId}"))],
                ],
            ],
            '選手の登録・編集・削除・CSV取込(003)' => [
                'allowed' => [$rep, $officer],
                'abilities' => ['managePlayers'],
                'calls' => [
                    ['call' => fn (PermissionContext $c) => $c->test->postJson($c->url('/players'), ['display_name' => '選手', 'grade' => 'e1'])],
                    ['call' => fn (PermissionContext $c) => $c->test->patchJson($c->url("/players/{$c->playerId}"), ['number' => 7])],
                    ['call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/guardian-candidates'))],
                    ['call' => fn (PermissionContext $c) => $c->test->putJson($c->url("/players/{$c->playerId}/guardians"), ['membership_ids' => []])],
                    ['call' => fn (PermissionContext $c) => $c->test->get($c->url('/players/import/template'), ['Accept' => 'application/json'])],
                    ['call' => fn (PermissionContext $c) => $c->test->post($c->url('/players/import/preview'), ['file' => $upload()], ['Accept' => 'application/json'])],
                    ['call' => fn (PermissionContext $c) => $c->test->post($c->url('/players/import'), ['file' => $upload(), 'file_hash' => hash('sha256', $csv)], ['Accept' => 'application/json'])],
                    ['call' => fn (PermissionContext $c) => $c->test->deleteJson($c->url("/players/{$c->playerId}"))],
                ],
            ],
            // 名簿の2行は同じ API で、見られる範囲が変わる。全員分を見られない役割には、403 ではなく
            // 自分に紐づく選手だけを返し、紐づかない選手の詳細は404にする(ADR-0013、ADR-0016 の例外)
            '名簿の閲覧(全員分)(003)' => [
                'allowed' => $allPlayers,
                'abilities' => ['viewAllPlayers'],
                'calls' => [
                    [
                        'call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/players')),
                        'ok' => fn (TestResponse $r) => $r->assertOk()->assertJsonPath('scope', 'all'),
                        'denied' => fn (TestResponse $r) => $r->assertOk()->assertJsonPath('scope', 'own'),
                    ],
                    [
                        'call' => fn (PermissionContext $c) => $c->test->getJson($c->url("/players/{$c->playerId}")),
                        'ok' => fn (TestResponse $r) => $r->assertOk(),
                        'denied' => fn (TestResponse $r) => $r->assertNotFound(),
                    ],
                ],
            ],
            '名簿の閲覧(自分に紐づく選手のみ)(003)' => [
                'allowed' => [$parent],
                'abilities' => ['viewPlayers'],
                'calls' => [
                    [
                        'call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/players')),
                        'ok' => fn (TestResponse $r) => $r->assertOk()->assertJsonPath('scope', 'own'),
                        'denied' => fn (TestResponse $r) => $r->assertOk()->assertJsonPath('scope', 'all'),
                    ],
                ],
                // 全員分を見られる役割も持っていれば、そちらが優先される(役割の和の例外、ADR-0016)
                'decide' => fn (array $roles) => in_array($parent, $roles, true) && array_intersect(
                    array_map(fn (TeamRole $r) => $r->value, $roles),
                    array_map(fn (TeamRole $r) => $r->value, $allPlayers),
                ) === [],
            ],
            '監査ログの閲覧' => [
                'allowed' => [$rep],
                'abilities' => ['viewAuditLogs'],
                'calls' => [
                    ['call' => fn (PermissionContext $c) => $c->test->getJson($c->url('/audit-logs'))],
                ],
            ],
        ];
    }
}
