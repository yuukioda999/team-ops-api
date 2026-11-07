<?php

namespace Tests\Feature\Notices;

use App\Enums\NoticeStatus;
use App\Enums\TeamRole;
use App\Models\Notice;
use App\Models\TeamMembership;
use App\Models\User;

/**
 * 濫用の防止(AC-005-17・18、ADR-0018)。
 */
class NoticeAbuseTest extends NoticeTestCase
{
    private function scheduleAs(User $author): int
    {
        return (int) $this->actingAs($author)
            ->postJson($this->url(), $this->input(['title' => '予約', 'scheduled_at' => now()->addHour()->toIso8601String()]))
            ->assertCreated()
            ->json('notice.id');
    }

    public function test_AC_005_17_a_notice_by_a_removed_author_is_cancelled_instead_of_published(): void
    {
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $id = $this->scheduleAs($coach);
        $membership = TeamMembership::where('team_id', $this->team->id)->where('user_id', $coach->id)->sole();
        $this->actingAs($this->representative)->deleteJson("/api/teams/{$this->team->id}/members/{$membership->id}")->assertNoContent();

        $this->travel(2)->hours();
        $this->runCommand('notices:publish-due');

        $this->assertSame(NoticeStatus::Cancelled, Notice::findOrFail($id)->status);
        $this->assertSame([], $this->noticeRequests());
        $this->assertEquals(['reason' => 'author_not_permitted'], $this->audits('notice.cancelled')[0]->details);
        $this->assertNull($this->audits('notice.cancelled')[0]->actor_user_id);
    }

    public function test_AC_005_17_a_notice_by_an_author_who_became_a_parent_only_is_cancelled(): void
    {
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $id = $this->scheduleAs($coach);
        $membership = TeamMembership::where('team_id', $this->team->id)->where('user_id', $coach->id)->sole();
        $this->actingAs($this->representative)->putJson("/api/teams/{$this->team->id}/members/{$membership->id}/roles", ['roles' => ['parent']])->assertOk();

        $this->travel(2)->hours();
        $this->runCommand('notices:publish-due');

        $this->assertSame(NoticeStatus::Cancelled, Notice::findOrFail($id)->status);
        $this->assertSame([], $this->noticeRequests());
    }

    public function test_AC_005_17_a_notice_whose_author_account_was_deleted_is_cancelled(): void
    {
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $id = $this->scheduleAs($coach);
        $coach->delete();

        $this->travel(2)->hours();
        $this->runCommand('notices:publish-due');

        $this->assertSame(NoticeStatus::Cancelled, Notice::findOrFail($id)->status);
        $this->assertSame([], $this->noticeRequests());
    }

    public function test_AC_005_17_an_author_who_still_has_the_right_is_published(): void
    {
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $id = $this->scheduleAs($coach);
        // コーチから役員に変わっても、作成できる役割なので公開される
        $membership = TeamMembership::where('team_id', $this->team->id)->where('user_id', $coach->id)->sole();
        $this->actingAs($this->representative)->putJson("/api/teams/{$this->team->id}/members/{$membership->id}/roles", ['roles' => ['officer']])->assertOk();

        $this->travel(2)->hours();
        $this->runCommand('notices:publish-due');

        $this->assertSame(NoticeStatus::Published, Notice::findOrFail($id)->status);
        $this->assertCount(1, $this->noticeRequests());
    }

    public function test_AC_005_18_the_11th_notice_in_an_hour_is_429(): void
    {
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($coach)->postJson($this->url(), $this->input(['title' => "連絡{$i}"]))->assertCreated();
        }
        // 入力の誤りで作れなかったものは数えないので、これは 422
        $this->actingAs($coach)->postJson($this->url(), $this->input(['title' => '']))->assertUnprocessable();
        $this->actingAs($coach)->postJson($this->url(), $this->input(['title' => '11件目']))->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(10, Notice::count());
        // ほかの人は作れる(1人ごとの上限)
        $this->actingAs($this->representative)->postJson($this->url(), $this->input())->assertCreated();
        // 1時間たてば、また作れる
        $this->travel(61)->minutes();
        $this->actingAs($coach)->postJson($this->url(), $this->input())->assertCreated();
    }

    public function test_AC_005_18_the_31st_recipient_count_in_a_minute_is_429(): void
    {
        $count = fn () => $this->actingAs($this->representative)->postJson($this->url('/recipient-count'), ['target' => ['type' => 'all', 'values' => []]]);
        for ($i = 0; $i < 30; $i++) {
            $count()->assertOk();
        }
        $count()->assertStatus(429);
        $this->travel(61)->seconds();
        $count()->assertOk();
    }
}
