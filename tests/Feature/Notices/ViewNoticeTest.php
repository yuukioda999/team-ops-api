<?php

namespace Tests\Feature\Notices;

use App\Enums\Grade;
use App\Enums\NoticeStatus;
use App\Enums\NoticeTarget;
use App\Enums\TeamRole;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\TeamMembership;

/**
 * お知らせの閲覧(AC-005-14・15・19)。
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

    public function test_AC_005_19_older_notices_are_returned_50_at_a_time(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        // 公開日時が同じものを含めて55件(1分おきに5件ずつ)
        $base = now()->subDay();
        for ($i = 0; $i < 55; $i++) {
            $notice = Notice::create(['team_id' => $this->team->id, 'author_user_id' => $this->representative->id, 'title' => "連絡{$i}", 'body' => '本文', 'target_type' => NoticeTarget::All, 'target_values' => [], 'status' => NoticeStatus::Published, 'published_at' => $base->copy()->addMinutes(intdiv($i, 5)), 'recipient_count' => 1]);
            $notice->recipients()->create(['user_id' => $parent->id]);
        }

        $first = $this->actingAs($parent)->getJson($this->url())->assertOk();
        $this->assertCount(50, (array) $first->json('notices'));
        $this->assertSame('連絡54', $first->json('notices.0.title'));
        $next = $first->json('next_before');
        $this->assertIsInt($next);

        $second = $this->actingAs($parent)->getJson($this->url("?before={$next}"))->assertOk()->assertJsonPath('next_before', null);
        $all = [...array_column((array) $first->json('notices'), 'title'), ...array_column((array) $second->json('notices'), 'title')];
        // 重なりも抜けもなく、新しい順
        $this->assertSame(array_map(fn (int $i) => "連絡{$i}", range(54, 0)), $all);

        // 自分宛てでないお知らせを起点にしても、続きは見えない
        $other = Notice::create(['team_id' => $this->team->id, 'author_user_id' => $this->representative->id, 'title' => '別', 'body' => '本文', 'target_type' => NoticeTarget::All, 'target_values' => [], 'status' => NoticeStatus::Published, 'published_at' => now(), 'recipient_count' => 0]);
        $this->actingAs($parent)->getJson($this->url("?before={$other->id}"))->assertOk()->assertJsonPath('notices', [])->assertJsonPath('next_before', null);
        $this->actingAs($parent)->getJson($this->url('?before=abc'))->assertUnprocessable();
    }
}
