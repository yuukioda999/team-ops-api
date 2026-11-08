<?php

namespace Tests\Feature\Demo;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\User;
use App\UseCases\Demo\CreateDemoTeam;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * デモとしてログイン(AC-007-01・02・05・09・10・17)。
 */
class DemoSessionTest extends DemoTestCase
{
    public function test_AC_007_01_the_demo_mode_is_advertised(): void
    {
        $this->getJson('/api/demo')->assertOk()->assertExactJson(['enabled' => true, 'lifetime_hours' => 24]);
    }

    public function test_AC_007_02_a_demo_team_with_fictional_data_is_made_and_the_visitor_is_logged_in_quickly(): void
    {
        $started = microtime(true);
        $login = $this->demoLogin('representative')->assertCreated();
        $this->assertLessThan(3.0, microtime(true) - $started, 'AC-007-02 は3秒以内');

        $team = $this->demoTeam($login);
        $this->assertTrue($team->isDemo());
        $this->assertEqualsWithDelta(now()->addHours(24)->getTimestamp(), $team->demo_expires_at?->getTimestamp(), 5);
        $login->assertJsonPath('teams.0.id', $team->id)->assertJsonPath('teams.0.roles', ['representative']);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('email', $login->json('email'));

        // 架空のデータ:代表・役員・コーチ2・保護者20、選手20(全員に保護者)、承認待ち2、送信済み2と予約1
        $this->assertSame(24, $team->members()->count());
        $this->assertSame(20, Player::where('team_id', $team->id)->count());
        $this->assertSame(20, Player::where('team_id', $team->id)->whereHas('guardians')->count());
        $this->assertSame(2, TeamJoinRequest::where('team_id', $team->id)->where('status', 'pending')->count());
        $this->assertSame(2, Notice::where('team_id', $team->id)->where('status', NoticeStatus::Published)->count());
        $this->assertSame(1, Notice::where('team_id', $team->id)->where('status', NoticeStatus::Scheduled)->count());
        $this->assertGreaterThan(0, NoticeRecipient::whereIn('notice_id', Notice::where('team_id', $team->id)->select('id'))->whereNotNull('read_at')->count());
        // デモのメールアドレスは、すべてこのチームの .invalid ドメイン
        foreach (User::where('demo_team_id', $team->id)->get() as $user) {
            $this->assertStringEndsWith("@demo-{$team->id}.invalid", $user->email);
        }
        $this->assertSame(26, User::where('demo_team_id', $team->id)->count());
        // デモチームを作るだけではメールを送らない
        $this->assertSame([], $this->relayed);
    }

    public function test_AC_007_02_each_role_logs_in_as_a_member_with_that_role(): void
    {
        foreach (['representative', 'officer', 'coach', 'parent'] as $role) {
            $this->demoLogin($role)->assertCreated()->assertJsonPath('teams.0.roles', [$role]);
        }
        $this->assertSame(4, Team::whereNotNull('demo_expires_at')->count());
    }

    public function test_AC_007_05_switching_roles_keeps_the_same_demo_team(): void
    {
        $login = $this->demoLogin('representative')->assertCreated();
        $team = $this->demoTeam($login);
        $players = Player::where('team_id', $team->id)->count();

        $switched = $this->postJson('/api/demo/sessions/switch', ['role' => 'parent'])->assertOk()
            ->assertJsonPath('demo_team_id', $team->id)
            ->assertJsonPath('teams.0.id', $team->id)
            ->assertJsonPath('teams.0.roles', ['parent']);
        $this->keepSession($switched);
        $this->getJson("/api/teams/{$team->id}/players")->assertOk();
        $this->assertSame($players, Player::where('team_id', $team->id)->count());

        $this->postJson('/api/demo/sessions/switch', ['role' => 'coach'])->assertOk()->assertJsonPath('teams.0.roles', ['coach']);
    }

    public function test_AC_007_05_switching_needs_your_own_demo_team(): void
    {
        // ログインしていなければ 401
        $this->postJson('/api/demo/sessions/switch', ['role' => 'parent'])->assertUnauthorized();
        // セッションのデモチームに属さないユーザー(ここでは別のチームに移ったことにする)は切り替えられない
        $login = $this->demoLogin('representative')->assertCreated();
        $this->demoUser($login)->forceFill(['demo_team_id' => 'other-team'])->save();
        $this->postJson('/api/demo/sessions/switch', ['role' => 'parent'])->assertForbidden();
    }

    public function test_AC_007_09_the_21st_demo_team_in_an_hour_from_one_ip_is_refused(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->demoLogin('parent', '203.0.113.20')->assertCreated();
        }
        $this->demoLogin('parent', '203.0.113.20')->assertStatus(429);
        // ほかの IP からは作れる
        $this->demoLogin('parent', '203.0.113.21')->assertCreated();
        $this->assertSame(21, Team::whereNotNull('demo_expires_at')->count());
    }

    public function test_AC_007_10_no_more_than_500_demo_teams_and_an_alert(): void
    {
        $now = now();
        $rows = [];
        for ($i = 0; $i < CreateDemoTeam::MAX_TEAMS; $i++) {
            $rows[] = ['id' => strtolower((string) Str::ulid()), 'name' => 'デモ', 'sport' => 'soccer', 'demo_expires_at' => $now->copy()->addDay(), 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            Team::insert($chunk);
        }
        $log = Log::spy();

        $this->demoLogin()->assertStatus(503)->assertJsonPath('message', 'デモが混み合っています。時間をおいてもう一度お試しください。');

        $this->assertSame(CreateDemoTeam::MAX_TEAMS, Team::whereNotNull('demo_expires_at')->count());
        $log->shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['active'] === CreateDemoTeam::MAX_TEAMS);
    }

    public function test_AC_007_17_without_demo_mode_the_demo_api_does_not_exist(): void
    {
        config(['team_ops.demo_mode' => false]);

        $this->getJson('/api/demo')->assertNotFound();
        $this->demoLogin()->assertNotFound();
        $this->postJson('/api/demo/sessions/switch', ['role' => 'parent'])->assertNotFound();
        $this->assertSame(0, Team::count());
    }
}
