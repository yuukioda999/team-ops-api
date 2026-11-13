<?php

namespace Tests\Feature\LoadTest;

use App\Enums\NoticeStatus;
use App\Jobs\DeliverOutboxMessage;
use App\Models\Notice;
use App\Models\OutboxMessage;
use App\Models\Team;
use App\Models\TeamMemberRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SeedLoadTestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['team_ops.loadtest_seed_enabled' => true, 'team_ops.demo_mode' => false]);
    }

    public function test_AC_008_02_creates_teams_of_members_and_one_scheduled_notice_each(): void
    {
        $at = now()->addMinutes(10)->startOfSecond();

        $this->assertSame(0, Artisan::call('loadtest:seed', ['--teams' => 3, '--members' => 6, '--at' => $at->toIso8601String()]));

        $this->assertSame(3, Team::count());
        $this->assertSame(18, User::count());
        $this->assertSame(18, DB::table('team_memberships')->count());
        $this->assertSame(3, TeamMemberRole::where('role', 'representative')->count());
        $this->assertSame(3, TeamMemberRole::where('role', 'officer')->count());
        $this->assertSame(3, TeamMemberRole::where('role', 'coach')->count());
        $this->assertSame(9, TeamMemberRole::where('role', 'parent')->count());
        $team = Team::firstOrFail();
        $this->assertNotNull($team->created_by);
        // HasUlids と同じく小文字のIDで保存する(ルートの解決は小文字で探す)
        $this->assertSame(strtolower($team->id), $team->id);
        $notice = Notice::where('team_id', $team->id)->sole();
        $this->assertSame(NoticeStatus::Scheduled, $notice->status);
        $this->assertTrue($notice->scheduled_at?->equalTo($at));
        $this->assertSame('負荷試験のお知らせ', $notice->title);
        // 暗号化とブラインドインデックスは、ふつうに保存したユーザーと同じに読める
        $user = User::findOrFail($team->created_by);
        $this->assertStringEndsWith('@loadtest.invalid', $user->email);
        $this->assertTrue(User::whereEmail($user->email)->exists());
        $this->assertStringStartsWith('試験 ', (string) $user->display_name);
    }

    public function test_AC_008_02_the_scheduled_notices_are_published_to_every_member_as_usual(): void
    {
        // 公開のジョブは動かし(sync)、relay への依頼のジョブだけを止める
        Queue::fake([DeliverOutboxMessage::class]);
        config(['team_ops.invalid_recipients_only' => true]);
        $this->assertSame(0, Artisan::call('loadtest:seed', ['--teams' => 2, '--members' => 5, '--at' => now()->addMinute()->toIso8601String()]));

        $this->travel(2)->minutes();
        Artisan::call('notices:publish-due');

        $this->assertSame(2, Notice::where('status', NoticeStatus::Published)->count());
        $this->assertSame([5, 5], Notice::orderBy('id')->pluck('recipient_count')->all());
        $this->assertSame(10, DB::table('notice_recipients')->count());
        $this->assertSame(2, OutboxMessage::count());
    }

    public function test_AC_008_02_running_it_again_adds_teams_without_clashing_addresses(): void
    {
        $first = Artisan::call('loadtest:seed', ['--teams' => 2, '--members' => 4]);
        $second = Artisan::call('loadtest:seed', ['--teams' => 2, '--members' => 4]);

        $this->assertSame([0, 0], [$first, $second]);

        $this->assertSame(4, Team::count());
        $this->assertSame(16, User::count());
    }

    public function test_AC_008_03_refuses_to_run_outside_local_testing_and_loadtest(): void
    {
        $this->app['env'] = 'production';

        $this->assertSame(1, Artisan::call('loadtest:seed', ['--teams' => 2]));

        $this->assertSame(0, Team::count());
        $this->assertSame(0, User::count());
    }

    public function test_AC_008_03_refuses_without_the_setting_even_in_a_local_environment(): void
    {
        // APP_ENV は artisan の --env で上書きできるので、設定がなければ local でも作らない
        config(['team_ops.loadtest_seed_enabled' => false]);

        $this->assertSame(1, Artisan::call('loadtest:seed', ['--teams' => 2]));

        $this->assertSame(0, Team::count());
    }

    public function test_AC_008_03_refuses_on_the_public_demo_even_with_the_setting(): void
    {
        config(['team_ops.demo_mode' => true]);

        $this->assertSame(1, Artisan::call('loadtest:seed', ['--teams' => 2]));

        $this->assertSame(0, Team::count());
    }

    public function test_AC_008_02_a_team_needs_at_least_four_members(): void
    {
        $this->assertSame(1, Artisan::call('loadtest:seed', ['--teams' => 1, '--members' => 3]));

        $this->assertSame(0, Team::count());
    }

    public function test_AC_008_07_sessions_need_a_file_to_be_written_to(): void
    {
        $this->assertSame(1, Artisan::call('loadtest:seed', ['--teams' => 1, '--sessions' => 2]));

        $this->assertSame(0, Team::count());
    }

    public function test_AC_008_02_the_rows_have_the_same_columns_filled_as_rows_the_app_creates(): void
    {
        // 行をまとめて insert するので、モデルの既定値やイベントが増えたときに黙って食い違わないよう、
        // ふつうに作った行と、値の入っている列の集合を比べる
        $appTeam = Team::factory()->create();
        $appUser = User::factory()->create();
        $this->assertSame(0, Artisan::call('loadtest:seed', ['--teams' => 1, '--members' => 4]));
        $filled = fn (string $table, int|string $id): array => array_keys(array_filter((array) DB::table($table)->find($id), fn ($v) => $v !== null));
        $seededTeam = Team::where('id', '!=', $appTeam->id)->sole();
        $seededUser = User::where('id', '!=', $appUser->id)->where('id', '!=', $appTeam->created_by)->firstOrFail();

        $this->assertEqualsCanonicalizing($filled('teams', $appTeam->id), $filled('teams', $seededTeam->id));
        $this->assertEqualsCanonicalizing($filled('users', $appUser->id), $filled('users', $seededUser->id));
    }

    public function test_AC_008_07_the_written_sessions_are_logged_in_as_the_chosen_members(): void
    {
        config(['session.driver' => 'database']);
        $out = tempnam(sys_get_temp_dir(), 'sessions');
        $this->assertSame(0, Artisan::call('loadtest:seed', ['--teams' => 2, '--members' => 5, '--sessions' => 3, '--sessions-out' => $out]));

        /** @var list<array{team_id: string, role: string, cookie_name: string, cookie_value: string}> $sessions */
        $sessions = json_decode((string) file_get_contents($out), true, flags: JSON_THROW_ON_ERROR);
        unlink($out);
        $this->assertCount(3, $sessions);
        $this->assertSame(['representative', 'parent', 'representative'], array_column($sessions, 'role'));

        foreach ($sessions as $session) {
            $this->flushSession();
            app('auth')->forgetGuards();
            $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'])
                ->withCredentials()
                ->withUnencryptedCookie($session['cookie_name'], $session['cookie_value'])
                ->getJson("/api/teams/{$session['team_id']}")
                ->assertOk()
                ->assertJsonPath('id', $session['team_id']);
        }
    }

    public function test_AC_008_03_sessions_are_not_written_outside_local_testing_and_loadtest(): void
    {
        $this->app['env'] = 'production';
        $out = sys_get_temp_dir().'/never-'.uniqid().'.json';

        $this->assertSame(1, Artisan::call('loadtest:seed', ['--teams' => 1, '--sessions' => 2, '--sessions-out' => $out]));

        $this->assertFileDoesNotExist($out);
    }
}
