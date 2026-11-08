<?php

namespace Tests\Feature\Demo;

use App\Models\AuditLog;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\OutboxMessage;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * デモチームの削除と期限切れ(AC-007-07・08・19)、送信されたメール(AC-007-11・12)。訪問者どうしの分離は tests/Tenancy/DemoIsolationTest。
 */
class DemoLifetimeTest extends DemoTestCase
{
    public function test_AC_007_07_an_expired_demo_team_is_deleted_with_everything_attached(): void
    {
        $login = $this->demoLogin('representative')->assertCreated();
        $team = $this->demoTeam($login);
        $this->postJson("/api/teams/{$team->id}/notices", ['title' => '連絡', 'body' => '本文', 'target' => ['type' => 'all', 'values' => []]])->assertCreated();
        $this->assertGreaterThan(0, OutboxMessage::count());
        $this->assertGreaterThan(0, AuditLog::where('team_id', $team->id)->count());
        // 通常のチームは消さない
        $normal = Team::factory()->withRepresentative()->create();

        $this->travel(23)->hours();
        Artisan::call('demo:prune');
        $this->assertNotNull(Team::find($team->id));

        $this->travel(2)->hours();
        Artisan::call('demo:prune');

        $this->assertNull(Team::find($team->id));
        $this->assertSame(0, User::where('demo_team_id', $team->id)->count());
        $this->assertSame(0, TeamMembership::where('team_id', $team->id)->count());
        $this->assertSame(0, Player::where('team_id', $team->id)->count());
        $this->assertSame(0, Notice::where('team_id', $team->id)->count());
        $this->assertSame(0, NoticeRecipient::count());
        $this->assertSame(0, AuditLog::where('team_id', $team->id)->count());
        $this->assertSame(0, OutboxMessage::where('dedupe_key', 'like', "team:{$team->id}:%")->count());
        $this->assertContains("to:\"@demo-{$team->id}.invalid\"", $this->mailpitDeleted);
        $this->assertNotNull(Team::find($normal->id));
    }

    public function test_AC_007_07_the_ip_and_browser_of_the_visitors_sessions_are_deleted_with_the_demo_team(): void
    {
        $team = $this->demoTeam($this->demoLogin('representative')->assertCreated());
        $userIds = User::where('demo_team_id', $team->id)->pluck('id');
        $this->assertGreaterThan(0, DB::table('sessions')->whereIn('user_id', $userIds)->whereNotNull('ip_address')->count());

        $this->travel(25)->hours();
        Artisan::call('demo:prune');

        $this->assertSame(0, DB::table('sessions')->whereIn('user_id', $userIds)->count());
        $this->assertSame(0, DB::table('sessions')->whereNotNull('ip_address')->count());
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_agent')->where('user_agent', '!=', '')->count());
    }

    public function test_AC_007_07_mails_that_arrive_late_or_were_left_behind_are_deleted_later(): void
    {
        $this->demoTeam($this->demoLogin('representative')->assertCreated());
        $this->travel(25)->hours();
        Artisan::call('demo:prune');

        // 削除のあとに届いたメールや消し残しは、受け取ってから25時間を過ぎたものを毎回消す
        $before = now()->subHours(25)->utc()->format('Y-m-d\\TH:i:s\\Z');
        $this->assertContains("to:\"@demo-\" before:\"{$before}\"", $this->mailpitDeleted);
    }

    public function test_AC_007_07_when_mailpit_is_down_the_teams_are_still_deleted_without_waiting_for_each(): void
    {
        $this->demoLogin('representative', '203.0.113.40')->assertCreated();
        $this->demoLogin('representative', '203.0.113.41')->assertCreated();
        $this->mailpitDown = true;
        $this->travel(25)->hours();

        $this->assertSame(0, Artisan::call('demo:prune'));

        $this->assertSame(0, Team::whereNotNull('demo_expires_at')->count());
        // 1回目でつながらないと分かったら、残りのチームの分と古いメールの削除は、この回は頼まない
        $this->assertSame(1, $this->mailpitDeleteAttempts);
    }

    public function test_AC_007_08_a_session_of_a_deleted_demo_team_is_told_it_expired(): void
    {
        $this->demoLogin('parent')->assertCreated();
        $this->getJson('/api/me')->assertOk();

        $this->travel(25)->hours();
        Artisan::call('demo:prune');

        $this->getJson('/api/me')->assertUnauthorized()->assertJsonPath('reason', 'demo_expired')->assertJsonPath('message', 'デモの有効期限が切れました');
        // 一度伝えたら、ふつうの未ログインに戻る
        $this->getJson('/api/me')->assertUnauthorized()->assertJsonMissingPath('reason');
    }

    public function test_AC_007_19_demo_reset_deletes_every_demo_team(): void
    {
        $this->demoLogin('representative')->assertCreated();
        $this->demoLogin('parent', '203.0.113.11')->assertCreated();
        $normal = Team::factory()->withRepresentative()->create();

        $this->assertSame(0, Artisan::call('demo:prune', ['--all' => true]));

        $this->assertSame(0, Team::whereNotNull('demo_expires_at')->count());
        $this->assertSame(0, User::whereNotNull('demo_team_id')->count());
        $this->assertNotNull(Team::find($normal->id));
    }

    public function test_AC_007_08_switching_roles_after_expiry_is_told_it_expired(): void
    {
        $this->demoLogin('parent')->assertCreated();
        $this->travel(25)->hours();
        Artisan::call('demo:prune');

        $this->postJson('/api/demo/sessions/switch', ['role' => 'coach'])->assertUnauthorized()
            ->assertJsonPath('reason', 'demo_expired')->assertJsonPath('message', 'デモの有効期限が切れました');
    }

    public function test_AC_007_12_sent_mails_of_this_demo_team_only_newest_first(): void
    {
        $team = $this->demoTeam($this->demoLogin('representative')->assertCreated());
        $mine = "demo-{$team->id}.invalid";
        // Mailpit の返す順に頼らず並べることを確かめるため、わざと古い順に置く
        $this->mailbox = [
            ['ID' => 'm1', 'Subject' => '【みどり台FC U-10(デモ)】古い連絡', 'To' => [['Address' => "parent2@{$mine}"], ['Address' => "parent3@{$mine}"]], 'Created' => '2026-11-08T00:00:00Z', 'Text' => '古い本文'],
            // ほかの訪問者のドメインを含むメール(部分一致で見つかっても出さない)
            ['ID' => 'm2', 'Subject' => '他の訪問者', 'To' => [['Address' => "parent1@{$mine}.other.invalid"]], 'Created' => '2026-11-08T00:30:00Z', 'Text' => '他人'],
            ['ID' => 'm3', 'Subject' => '【みどり台FC U-10(デモ)】新しい連絡', 'To' => [['Address' => "parent1@{$mine}"]], 'Created' => '2026-11-08T01:00:00Z', 'Text' => "新しい本文\n"],
        ];

        $this->getJson("/api/teams/{$team->id}/demo/mails")->assertOk()->assertExactJson(['mails' => [
            ['subject' => '【みどり台FC U-10(デモ)】新しい連絡', 'to' => ["parent1@{$mine}"], 'sent_at' => '2026-11-08T01:00:00Z', 'text' => "新しい本文\n"],
            ['subject' => '【みどり台FC U-10(デモ)】古い連絡', 'to' => ["parent2@{$mine}", "parent3@{$mine}"], 'sent_at' => '2026-11-08T00:00:00Z', 'text' => '古い本文'],
        ]]);
    }

    public function test_AC_007_12_sent_mails_are_not_available_outside_demo_teams(): void
    {
        $team = $this->demoTeam($this->demoLogin('representative')->assertCreated());
        config(['team_ops.demo_mode' => false]);
        $this->getJson("/api/teams/{$team->id}/demo/mails")->assertNotFound();
    }
}
