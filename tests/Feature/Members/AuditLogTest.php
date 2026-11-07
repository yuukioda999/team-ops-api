<?php

namespace Tests\Feature\Members;

use App\Enums\TeamRole;
use App\Models\TeamMembership;
use App\Support\Audit\Audit;
use App\Support\Audit\AuditActionLabel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

/**
 * 監査ログの閲覧(AC-004-12)。
 */
class AuditLogTest extends MemberTestCase
{
    public function test_AC_004_12_the_log_shows_time_actor_and_action_in_japanese_newest_first_without_details(): void
    {
        $officer = $this->member([TeamRole::Officer], '佐藤 役員');
        $this->actingAs($officer)->postJson($this->url('/invitation'))->assertSuccessful();
        $this->actingAs($this->representative)->patchJson($this->url(), ['name' => '新しい名前'])->assertOk();

        $response = $this->actingAs($this->representative)->getJson($this->url('/audit-logs'))->assertOk()->assertJsonPath('next_before', null);

        $logs = (array) $response->json('logs');
        $this->assertSame(['チーム名・競技を変更', '招待リンクを発行'], array_column($logs, 'action_label'));
        $this->assertSame(['代表 太郎', '佐藤 役員'], array_column($logs, 'actor_display_name'));
        $this->assertSame(['member', 'member'], array_column($logs, 'actor'));
        $this->assertSame(['id', 'created_at', 'action', 'action_label', 'actor', 'actor_display_name'], array_keys($logs[0]));
        $this->assertStringNotContainsString('fields', (string) $response->getContent());
    }

    public function test_AC_004_12_pages_of_100_with_next_before(): void
    {
        $now = now();
        DB::table('audit_logs')->insert(array_map(fn (int $i) => [
            'team_id' => $this->team->id, 'actor_user_id' => $this->representative->id, 'action' => 'player.viewed_list',
            'subject_type' => null, 'subject_id' => null, 'details' => null, 'created_at' => $now,
        ], range(1, 150)));

        $first = $this->actingAs($this->representative)->getJson($this->url('/audit-logs'))->assertOk()->assertJsonCount(100, 'logs');
        $before = $first->json('next_before');
        $this->assertIsInt($before);
        $this->assertSame($before, $first->json('logs.99.id'));

        $second = $this->actingAs($this->representative)->getJson($this->url('/audit-logs?before='.$before))->assertOk()->assertJsonCount(50, 'logs')->assertJsonPath('next_before', null);
        $this->assertLessThan($before, $second->json('logs.0.id'));
    }

    public function test_AC_004_12_an_actor_who_left_the_team_is_not_named(): void
    {
        $parent = $this->member([TeamRole::Parent], '山田 花子');
        Audit::record($this->team, $parent, 'player.viewed_list', null, ['scope' => 'own']);
        TeamMembership::where('team_id', $this->team->id)->where('user_id', $parent->id)->delete();
        Audit::record($this->team, null, 'join_request.rejected');

        $response = $this->actingAs($this->representative)->getJson($this->url('/audit-logs'))->assertOk();

        // アカウントのない記録(actor_user_id が null)も、チームにいない人として扱う
        $this->assertSame([['former', null], ['former', null]], array_map(fn (array $l) => [$l['actor'], $l['actor_display_name']], (array) $response->json('logs')));
        $this->assertStringNotContainsString('山田', (string) $response->getContent());
    }

    public function test_a_team_cannot_be_the_audit_subject_because_subject_id_is_numeric(): void
    {
        // SQLite は文字列をそのまま入れてしまい、MySQL だけで落ちていた。どの DB でも同じように止める
        $this->expectException(\InvalidArgumentException::class);
        Audit::record($this->team, $this->representative, 'team.updated', $this->team);
    }

    public function test_AC_004_12_every_recorded_action_has_a_japanese_label(): void
    {
        $actions = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            preg_match_all("/Audit::record\\([^;]*?'([a-z_]+\\.[a-z_]+)'/s", $file->getContents(), $m);
            array_push($actions, ...$m[1]);
        }
        $actions = array_values(array_unique($actions));

        $this->assertNotEmpty($actions);
        $this->assertSame([], array_values(array_diff($actions, array_keys(AuditActionLabel::LABELS))), '監査ログの action を足したら、AuditActionLabel にも日本語を足してください。');
    }
}
