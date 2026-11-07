<?php

namespace Tests\Feature\Notices;

use App\Enums\TeamRole;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\NoticeReminder;
use App\Models\TeamMembership;
use App\Models\User;

/**
 * 既読(AC-006-01・03)、配信状況(AC-006-04〜07・10)、再通知(AC-006-11〜15)。ADR-0019・0020。
 */
class NoticeStatusTest extends NoticeTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function send(?User $as = null, array $overrides = []): Notice
    {
        $id = (int) $this->actingAs($as ?? $this->representative)->postJson($this->url(), $this->input($overrides))->assertCreated()->json('notice.id');

        return Notice::findOrFail($id);
    }

    private function key(Notice $notice, string $suffix = 'part:1'): string
    {
        return "team:{$this->team->id}:notice:{$notice->id}:{$suffix}";
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryStatus(Notice $notice, ?User $as = null): array
    {
        return (array) $this->actingAs($as ?? $this->representative)->getJson($this->url("/{$notice->id}/status"))->assertOk()->json('status');
    }

    public function test_AC_006_01_AC_006_03_opening_a_notice_marks_it_read_and_the_list_shows_unread(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $first = $this->send(overrides: ['title' => '一つ目']);
        $this->travel(1)->minutes();
        $second = $this->send(overrides: ['title' => '二つ目']);

        $unread = fn () => array_column((array) $this->actingAs($parent)->getJson($this->url())->json('notices'), 'unread', 'title');
        $this->assertSame(['二つ目' => true, '一つ目' => true], $unread());

        $this->travel(1)->minutes();
        $openedAt = now()->toImmutable();
        $this->actingAs($parent)->getJson($this->url("/{$first->id}"))->assertOk()->assertJsonPath('notice.unread', false);
        $this->assertSame(['二つ目' => true, '一つ目' => false], $unread());

        // 2回目に開いても、初めて開いた日時は変わらない
        $this->travel(1)->hours();
        $this->actingAs($parent)->getJson($this->url("/{$first->id}"))->assertOk();
        $this->assertEquals($openedAt->startOfSecond(), NoticeRecipient::where('notice_id', $first->id)->where('user_id', $parent->id)->sole()->read_at?->startOfSecond());

        // 状況を開いても、送った本人の既読にはならない
        $this->deliveryStatus($second);
        $this->assertNull(NoticeRecipient::where('notice_id', $second->id)->where('user_id', $this->representative->id)->sole()->read_at);
    }

    public function test_AC_006_04_AC_006_07_counts_and_the_sending_state(): void
    {
        $parentA = $this->member([TeamRole::Parent], '保護者A');
        $parentB = $this->member([TeamRole::Parent], '保護者B');
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $notice = $this->send();

        $this->assertSame('来週の試合', $this->deliveryStatus($notice)['title']);
        $this->assertSame(['state' => 'sending', 'recipients' => 4, 'read' => 0, 'unread' => 4, 'delivered' => 0, 'pending' => 4, 'failed' => 0, 'skipped' => 0],
            array_diff_key($this->deliveryStatus($notice), ['reminders' => true, 'title' => true]));

        $this->results($this->key($notice), [$parentA->id => ['delivered'], $parentB->id => ['failed', 'invalid_address']])->assertNoContent();
        $this->actingAs($parentA)->getJson($this->url("/{$notice->id}"))->assertOk();
        $this->assertSame(['state' => 'sending', 'recipients' => 4, 'read' => 1, 'unread' => 3, 'delivered' => 1, 'pending' => 2, 'failed' => 1, 'skipped' => 0],
            array_diff_key($this->deliveryStatus($notice), ['reminders' => true, 'title' => true]));

        $this->results($this->key($notice), [$coach->id => ['delivered'], $this->representative->id => ['skipped', 'expired']])->assertNoContent();
        $this->assertSame('done', $this->deliveryStatus($notice)['state']);
    }

    public function test_AC_006_05_AC_006_06_unread_and_failed_members_by_name(): void
    {
        $parentA = $this->member([TeamRole::Parent], 'あべ');
        $parentB = $this->member([TeamRole::Parent], 'いとう');
        $removed = $this->member([TeamRole::Parent], 'うえだ');
        $notice = $this->send();
        $this->actingAs($this->representative)->getJson($this->url("/{$notice->id}"))->assertOk();
        $this->results($this->key($notice), [$parentA->id => ['failed', 'invalid_address'], $parentB->id => ['failed', 'provider_rejected']])->assertNoContent();
        TeamMembership::where('team_id', $this->team->id)->where('user_id', $removed->id)->delete();

        $this->actingAs($this->representative)->getJson($this->url("/{$notice->id}/status/unread"))->assertOk()->assertExactJson(['members' => [
            ['display_name' => 'あべ', 'member' => true],
            ['display_name' => 'いとう', 'member' => true],
            ['display_name' => 'うえだ', 'member' => false],
        ]]);
        $this->actingAs($this->representative)->getJson($this->url("/{$notice->id}/status/failures"))->assertOk()->assertExactJson(['members' => [
            ['display_name' => 'あべ', 'member' => true, 'status' => 'failed', 'reason' => 'invalid_address'],
            ['display_name' => 'いとう', 'member' => true, 'status' => 'failed', 'reason' => 'provider_rejected'],
        ]]);
    }

    public function test_AC_006_04_the_notice_says_whether_it_is_mine(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $notice = $this->send();

        $this->actingAs($this->representative)->getJson($this->url("/{$notice->id}"))->assertJsonPath('notice.mine', true);
        $this->actingAs($parent)->getJson($this->url("/{$notice->id}"))->assertJsonPath('notice.mine', false);
    }

    public function test_AC_006_10_parents_and_other_coaches_cannot_see_the_status(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $otherCoach = $this->member([TeamRole::Coach], '別のコーチ');
        $officer = $this->member([TeamRole::Officer], '役員');
        $byCoach = $this->send($coach);

        foreach (['/status', '/status/unread', '/status/failures'] as $path) {
            $this->actingAs($parent)->getJson($this->url("/{$byCoach->id}{$path}"))->assertForbidden();
            $this->actingAs($otherCoach)->getJson($this->url("/{$byCoach->id}{$path}"))->assertForbidden();
            $this->actingAs($coach)->getJson($this->url("/{$byCoach->id}{$path}"))->assertOk();
            $this->actingAs($officer)->getJson($this->url("/{$byCoach->id}{$path}"))->assertOk();
        }
        $this->actingAs($otherCoach)->postJson($this->url("/{$byCoach->id}/reminders"))->assertForbidden();
        $this->actingAs($parent)->postJson($this->url("/{$byCoach->id}/reminders"))->assertForbidden();

        // 予約中のお知らせには状況がない
        $scheduled = $this->send(overrides: ['scheduled_at' => now()->addHour()->toIso8601String()]);
        $this->actingAs($this->representative)->getJson($this->url("/{$scheduled->id}/status"))->assertNotFound();
    }

    public function test_AC_006_11_a_reminder_goes_only_to_members_who_have_not_read(): void
    {
        $readParent = $this->member([TeamRole::Parent], '読んだ保護者');
        $unreadParent = $this->member([TeamRole::Parent], '読んでいない保護者');
        $notice = $this->send();
        $this->actingAs($readParent)->getJson($this->url("/{$notice->id}"))->assertOk();
        $this->actingAs($this->representative)->getJson($this->url("/{$notice->id}"))->assertOk();
        $this->relayed = [];

        $this->actingAs($this->representative)->postJson($this->url("/{$notice->id}/reminders"))
            ->assertCreated()
            ->assertExactJson(['reminder' => ['number' => 1, 'sent' => 1, 'excluded' => 0]]);

        $this->assertSame([[$unreadParent->email]], $this->deliveredTo());
        $request = $this->noticeRequests()[0];
        $this->assertSame($this->key($notice, 'reminder:1:part:1'), $request['dedupe_key']);
        $this->assertSame('【再通知】【つくばFC】来週の試合', $request['message']['subject']);
        $this->assertEquals(['number' => 1, 'recipients' => 1, 'excluded' => 0], $this->audits('notice.reminded')[0]->details);
        $this->assertSame(1, NoticeReminder::where('notice_id', $notice->id)->sole()->sent_count);
    }

    public function test_AC_006_12_AC_006_13_once_an_hour_and_at_most_three_times(): void
    {
        $this->member([TeamRole::Parent], '保護者');
        $notice = $this->send();
        $remind = fn () => $this->actingAs($this->representative)->postJson($this->url("/{$notice->id}/reminders"));

        $remind()->assertCreated();
        $this->travel(59)->minutes();
        $remind()->assertStatus(409)->assertJsonValidationErrors(['reminder']);
        $this->assertSame('interval', $this->deliveryStatus($notice)['reminders']['reason']);
        $this->assertNotNull($this->deliveryStatus($notice)['reminders']['next_available_at']);

        $this->travel(2)->minutes();
        $remind()->assertCreated();
        $this->travel(61)->minutes();
        $remind()->assertCreated()->assertJsonPath('reminder.number', 3);
        $this->travel(61)->minutes();
        $remind()->assertStatus(409);
        $this->assertSame(['sent' => 3, 'max' => 3, 'available' => false, 'reason' => 'max', 'next_available_at' => null], $this->deliveryStatus($notice)['reminders']);
        $this->assertSame(3, NoticeReminder::where('notice_id', $notice->id)->count());
    }

    public function test_AC_006_14_failed_members_get_the_reminder_except_undeliverable_addresses(): void
    {
        $temporary = $this->member([TeamRole::Parent], '一時的に失敗');
        $invalid = $this->member([TeamRole::Parent], 'アドレスが存在しない');
        $notice = $this->send();
        $this->actingAs($this->representative)->getJson($this->url("/{$notice->id}"))->assertOk();
        $this->results($this->key($notice), [$temporary->id => ['failed', 'provider_unavailable'], $invalid->id => ['failed', 'invalid_address']])->assertNoContent();
        $this->relayed = [];

        $this->actingAs($this->representative)->postJson($this->url("/{$notice->id}/reminders"))
            ->assertCreated()
            ->assertJsonPath('reminder.sent', 1)
            ->assertJsonPath('reminder.excluded', 1);
        $this->assertSame([[$temporary->email]], $this->deliveredTo());
        // 送り直した人は送信待ちに戻る
        $this->assertSame('pending', NoticeRecipient::where('notice_id', $notice->id)->where('user_id', $temporary->id)->sole()->delivery_status->value);
    }

    public function test_AC_006_15_no_reminder_when_everyone_has_read(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $removed = $this->member([TeamRole::Parent], '抜けた保護者');
        $notice = $this->send();
        $this->actingAs($parent)->getJson($this->url("/{$notice->id}"))->assertOk();
        $this->actingAs($this->representative)->getJson($this->url("/{$notice->id}"))->assertOk();
        // 未読のまま抜けた人は数えない(お知らせを開けない)
        TeamMembership::where('team_id', $this->team->id)->where('user_id', $removed->id)->delete();

        $this->assertSame('no_unread', $this->deliveryStatus($notice)['reminders']['reason']);
        $this->actingAs($this->representative)->postJson($this->url("/{$notice->id}/reminders"))->assertStatus(409);
        $this->assertSame(0, NoticeReminder::count());
    }

    public function test_AC_006_08_a_late_result_of_the_first_send_does_not_overwrite_the_reminder(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $notice = $this->send();
        $sentAt = now()->toIso8601String();
        $this->travel(2)->hours();
        $this->actingAs($this->representative)->postJson($this->url("/{$notice->id}/reminders"))->assertCreated();

        // 最初の送信の結果が、再通知のあとに届いた
        $this->results($this->key($notice), [$parent->id => ['failed', 'provider_unavailable']], $sentAt)->assertNoContent();
        $this->assertSame('pending', NoticeRecipient::where('notice_id', $notice->id)->where('user_id', $parent->id)->sole()->delivery_status->value);

        $this->results($this->key($notice, 'reminder:1:part:1'), [$parent->id => ['delivered']])->assertNoContent();
        $this->assertSame('delivered', NoticeRecipient::where('notice_id', $notice->id)->where('user_id', $parent->id)->sole()->delivery_status->value);
    }
}
