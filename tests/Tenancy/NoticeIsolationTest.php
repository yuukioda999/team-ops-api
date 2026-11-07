<?php

namespace Tests\Tenancy;

use App\Enums\NoticeStatus;
use App\Enums\NoticeTarget;
use App\Enums\TeamRole;
use App\Models\Notice;
use App\Models\NoticeReminder;
use App\Models\Team;
use App\Models\User;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AC-000-01:他のチームのお知らせは、存在しない場合と同じ404。宛先にも他チームの人を混ぜない。
 */
class NoticeIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_AC_000_01_another_teams_notices_are_not_found_through_my_team(): void
    {
        $rep = User::factory()->create();
        $mine = Team::factory()->withRepresentative()->create(['created_by' => $rep->id]);
        $other = Team::factory()->withRepresentative()->create();
        $theirs = Notice::create(['team_id' => $other->id, 'author_user_id' => null, 'title' => '他チーム', 'body' => '本文', 'target_type' => NoticeTarget::All, 'target_values' => [], 'status' => NoticeStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
        $base = "/api/teams/{$mine->id}/notices";

        $this->actingAs($rep)->getJson("{$base}/{$theirs->id}")->assertNotFound();
        $this->actingAs($rep)->patchJson("{$base}/{$theirs->id}", ['title' => '乗っ取り'])->assertNotFound();
        $this->actingAs($rep)->deleteJson("{$base}/{$theirs->id}")->assertNotFound();
        $this->actingAs($rep)->getJson("{$base}/scheduled")->assertOk()->assertJsonPath('notices', []);
        $fresh = Notice::findOrFail($theirs->id);
        $this->assertSame('他チーム', $fresh->title);
        $this->assertSame(NoticeStatus::Scheduled, $fresh->status);
    }

    public function test_AC_000_01_a_non_member_gets_404_for_every_notice_route(): void
    {
        $outsider = User::factory()->create();
        $team = Team::factory()->withRepresentative()->create();
        $base = "/api/teams/{$team->id}/notices";

        $this->actingAs($outsider)->getJson($base)->assertNotFound();
        $this->actingAs($outsider)->postJson($base, ['title' => 'x', 'body' => 'x', 'target' => ['type' => 'all', 'values' => []]])->assertNotFound();
        $this->actingAs($outsider)->postJson("{$base}/recipient-count", ['target' => ['type' => 'all', 'values' => []]])->assertNotFound();
        $this->actingAs($outsider)->getJson("{$base}/scheduled")->assertNotFound();
        $this->assertSame(0, Notice::count());
    }

    public function test_AC_000_01_recipients_never_include_members_of_other_teams(): void
    {
        $rep = User::factory()->create();
        $mine = Team::factory()->withRepresentative()->create(['created_by' => $rep->id]);
        $other = Team::factory()->withRepresentative()->create();
        TeamFactory::addMember($other, User::factory()->create(), [TeamRole::Parent]);

        $this->actingAs($rep)->postJson("/api/teams/{$mine->id}/notices/recipient-count", ['target' => ['type' => 'roles', 'values' => ['parent']]])->assertJsonPath('count', 1);
        $this->actingAs($rep)->postJson("/api/teams/{$mine->id}/notices/recipient-count", ['target' => ['type' => 'all', 'values' => []]])->assertJsonPath('count', 1);
    }

    public function test_AC_000_01_the_status_and_reminders_of_another_teams_notice_are_not_found(): void
    {
        $rep = User::factory()->create();
        $mine = Team::factory()->withRepresentative()->create(['created_by' => $rep->id]);
        $other = Team::factory()->withRepresentative()->create();
        $theirs = Notice::create(['team_id' => $other->id, 'author_user_id' => null, 'title' => '他チーム', 'body' => '本文', 'target_type' => NoticeTarget::All, 'target_values' => [], 'status' => NoticeStatus::Published, 'published_at' => now(), 'recipient_count' => 1]);
        $theirs->recipients()->create(['user_id' => User::factory()->create()->id]);
        $base = "/api/teams/{$mine->id}/notices/{$theirs->id}";

        $this->actingAs($rep)->getJson("{$base}/status")->assertNotFound();
        $this->actingAs($rep)->getJson("{$base}/status/unread")->assertNotFound();
        $this->actingAs($rep)->getJson("{$base}/status/failures")->assertNotFound();
        $this->actingAs($rep)->postJson("{$base}/reminders")->assertNotFound();

        // チームにいない人は、チームの URL で 404
        $outside = "/api/teams/{$other->id}/notices/{$theirs->id}";
        $this->actingAs($rep)->getJson("{$outside}/status")->assertNotFound();
        $this->actingAs($rep)->postJson("{$outside}/reminders")->assertNotFound();
        $this->assertSame(0, NoticeReminder::count());
    }
}
