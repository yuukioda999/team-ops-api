<?php

namespace Tests\Feature\Demo;

use App\Models\NoticeRecipient;
use App\Models\OutboxMessage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * デモモードで止める操作と、宛先の制限(AC-007-03・13・14・15・16)。
 */
class DemoRestrictionTest extends DemoTestCase
{
    public function test_AC_007_03_AC_007_15_login_links_team_creation_and_email_changes_are_refused(): void
    {
        $this->postJson('/api/auth/login-links', ['email' => 'someone@example.com'])->assertForbidden()->assertJsonPath('reason', 'demo_mode');
        $this->postJson('/api/auth/login-links/consume', ['token' => str_repeat('a', 64)])->assertForbidden();

        $this->demoLogin()->assertCreated();
        $this->postJson('/api/teams', ['name' => '新しいチーム', 'sport' => 'soccer'])->assertForbidden()->assertJsonPath('reason', 'demo_mode');
        $this->postJson('/api/me/email-changes', ['new_email' => 'real@example.com'])->assertForbidden();
        $this->postJson('/api/me/email-changes/consume', ['token' => str_repeat('a', 64)])->assertForbidden();

        $this->assertSame(1, Team::count());
        $this->assertSame(0, OutboxMessage::count());
    }

    public function test_AC_007_14_an_invitation_can_be_issued_but_not_used_to_join(): void
    {
        $team = $this->demoTeam($this->demoLogin('representative')->assertCreated());

        $url = (string) $this->postJson("/api/teams/{$team->id}/invitation")->assertCreated()->json('invitation.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->postJson('/api/invitations/preview', ['token' => $query['token']])->assertOk();
        $this->postJson('/api/invitations/applications', ['token' => $query['token'], 'display_name' => '試す人'])->assertForbidden()->assertJsonPath('reason', 'demo_mode');

        // 承認は用意した申請で試せる
        $pending = (array) $this->getJson("/api/teams/{$team->id}/join-requests")->assertOk()->json('join_requests');
        $this->assertCount(2, $pending);
        $this->postJson("/api/teams/{$team->id}/join-requests/{$pending[0]['id']}/approve", ['roles' => ['parent']])->assertSuccessful();
        $this->assertCount(1, (array) $this->getJson("/api/teams/{$team->id}/join-requests")->json('join_requests'));
    }

    public function test_AC_007_13_AC_007_11_demo_mode_alone_limits_notice_mail_to_invalid_addresses(): void
    {
        $team = $this->demoTeam($this->demoLogin('representative')->assertCreated());
        // 何かの理由で実在のアドレスのメンバーがいても、デモモードでは送らない
        $real = User::whereEmail("parent1@demo-{$team->id}.invalid")->sole();
        $real->forceFill(['email' => 'real-person@example.com'])->save();

        $id = (int) $this->postJson("/api/teams/{$team->id}/notices", ['title' => 'デモの連絡', 'body' => '本文', 'target' => ['type' => 'all', 'values' => []]])->assertCreated()->json('notice.id');

        $addresses = array_merge(...array_map(fn (array $r) => array_column($r['recipients'], 'email'), $this->relayed));
        $this->assertNotContains('real-person@example.com', $addresses);
        $this->assertCount(23, $addresses);
        foreach ($addresses as $address) {
            $this->assertStringEndsWith("@demo-{$team->id}.invalid", $address);
        }
        $this->assertSame('restricted', NoticeRecipient::where('notice_id', $id)->where('user_id', $real->id)->sole()->delivery_error);
    }

    public function test_AC_007_16_the_sample_csv_has_20_fictional_players_and_can_be_imported(): void
    {
        $team = $this->demoTeam($this->demoLogin('representative')->assertCreated());

        $csv = $this->get("/api/teams/{$team->id}/players/import/sample")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $lines = array_values(array_filter(explode("\r\n", (string) $csv)));
        $this->assertSame("\u{FEFF}表示名,学年,背番号", $lines[0]);
        $this->assertCount(21, $lines);

        $file = UploadedFile::fake()->createWithContent('players.csv', (string) $csv);
        $this->post("/api/teams/{$team->id}/players/import/preview", ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('count', 20)
            ->assertJsonPath('errors', [])
            ->assertJsonPath('warnings', []);
    }
}
