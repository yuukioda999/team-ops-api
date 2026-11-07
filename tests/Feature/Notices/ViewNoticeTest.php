<?php

namespace Tests\Feature\Notices;

use App\Enums\Grade;
use App\Enums\TeamRole;
use App\Models\NoticeRecipient;
use App\Models\TeamMembership;

/**
 * お知らせの閲覧(AC-005-14・15)。
 */
class ViewNoticeTest extends NoticeTestCase
{
    public function test_AC_005_14_members_see_only_published_notices_addressed_to_them_newest_first(): void
    {
        $parent = $this->member([TeamRole::Parent], '小3の保護者');
        $otherParent = $this->member([TeamRole::Parent], '小5の保護者');
        $this->child(Grade::E3, $parent);
        $this->child(Grade::E5, $otherParent);

        $this->actingAs($this->representative)->postJson($this->url(), $this->input(['title' => '全員へ']))->assertCreated();
        $this->travel(1)->minutes();
        $this->actingAs($this->representative)->postJson($this->url(), $this->input(['title' => '小3へ', 'target' => ['type' => 'grades', 'values' => ['e3']]]))->assertCreated();
        $this->actingAs($this->representative)->postJson($this->url(), $this->input(['title' => '予約', 'scheduled_at' => now()->addHour()->toIso8601String()]))->assertCreated();

        $titles = fn ($user) => array_column((array) $this->actingAs($user)->getJson($this->url())->assertOk()->json('notices'), 'title');

        $this->assertSame(['小3へ', '全員へ'], $titles($parent));
        $this->assertSame(['全員へ'], $titles($otherParent));
        $this->actingAs($parent)->getJson($this->url())
            ->assertJsonPath('notices.0.author_display_name', '代表 太郎')
            ->assertJsonPath('notices.0.body', "集合は8時です。\n詳しくは https://example.invalid/match を見てください。");
    }

    public function test_AC_005_15_a_notice_not_addressed_to_you_is_404_like_a_missing_one(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $id = (int) $this->actingAs($this->representative)->postJson($this->url(), $this->input(['target' => ['type' => 'roles', 'values' => ['coach']]]))->json('notice.id');

        $this->actingAs($coach)->getJson($this->url("/{$id}"))->assertOk()->assertJsonPath('notice.id', $id);
        $notAddressed = $this->actingAs($parent)->getJson($this->url("/{$id}"))->assertNotFound()->getContent();
        $this->assertSame($this->actingAs($parent)->getJson($this->url('/999999'))->assertNotFound()->getContent(), $notAddressed);
    }

    public function test_AC_005_14_a_removed_member_cannot_see_notices_they_received(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $id = (int) $this->actingAs($this->representative)->postJson($this->url(), $this->input())->json('notice.id');
        $membership = TeamMembership::where('team_id', $this->team->id)->where('user_id', $parent->id)->sole();

        $this->actingAs($this->representative)->deleteJson("/api/teams/{$this->team->id}/members/{$membership->id}")->assertNoContent();

        $this->actingAs($parent)->getJson($this->url("/{$id}"))->assertNotFound();
        // 006 の母数のため、送信時点の宛先は残る(ADR-0017)
        $this->assertSame(2, NoticeRecipient::where('notice_id', $id)->count());
    }
}
