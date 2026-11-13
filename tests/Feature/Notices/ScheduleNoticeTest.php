<?php

namespace Tests\Feature\Notices;

use App\Enums\Grade;
use App\Enums\NoticeStatus;
use App\Enums\TeamRole;
use App\Jobs\PublishScheduledNoticeJob;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\UseCases\Notices\PublishScheduledNotice;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * 予約送信(AC-005-09〜13、ADR-0018)。
 */
class ScheduleNoticeTest extends NoticeTestCase
{
    /**
     * @param  array{type: string, values: list<string>}  $target
     */
    private function schedule(int $minutes, string $title = '翌朝の連絡', array $target = ['type' => 'all', 'values' => []]): int
    {
        return (int) $this->actingAs($this->representative)
            ->postJson($this->url(), $this->input(['title' => $title, 'target' => $target, 'scheduled_at' => now()->addMinutes($minutes)->toIso8601String()]))
            ->assertCreated()
            ->assertJsonPath('notice.status', 'scheduled')
            ->json('notice.id');
    }

    public function test_AC_005_09_a_scheduled_notice_is_not_published_or_mailed_before_its_time(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $id = $this->schedule(60);

        $this->runCommand('notices:publish-due');

        $this->assertSame(NoticeStatus::Scheduled, Notice::findOrFail($id)->status);
        $this->assertSame([], $this->noticeRequests());
        $this->assertSame(0, NoticeRecipient::count());
        $this->actingAs($parent)->getJson($this->url())->assertJsonPath('notices', []);
        $this->actingAs($parent)->getJson($this->url("/{$id}"))->assertNotFound();
        $this->assertEquals(['target' => 'all', 'values' => []], $this->audits('notice.scheduled')[0]->details);
    }

    public function test_AC_005_10_the_author_or_a_representative_or_officer_edits_and_cancels_a_scheduled_notice(): void
    {
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $otherCoach = $this->member([TeamRole::Coach], '別のコーチ');
        $officer = $this->member([TeamRole::Officer], '役員');
        $id = (int) $this->actingAs($coach)->postJson($this->url(), $this->input(['scheduled_at' => now()->addHour()->toIso8601String()]))->json('notice.id');

        // 作成者は編集できる
        $this->actingAs($coach)->patchJson($this->url("/{$id}"), ['title' => '時間変更', 'target' => ['type' => 'roles', 'values' => ['parent']], 'scheduled_at' => now()->addHours(2)->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('notice.title', '時間変更')
            ->assertJsonPath('notice.target', ['type' => 'roles', 'values' => ['parent']]);
        // 別のコーチは編集も取り消しもできない
        $this->actingAs($otherCoach)->patchJson($this->url("/{$id}"), ['title' => 'x'])->assertForbidden();
        $this->actingAs($otherCoach)->deleteJson($this->url("/{$id}"))->assertForbidden();
        // 役員は他人の予約も編集できる
        $this->actingAs($officer)->patchJson($this->url("/{$id}"), ['body' => '役員が直した本文'])->assertOk()->assertJsonPath('notice.body', '役員が直した本文');

        $this->assertEquals(['fields' => ['scheduled_at', 'target_type', 'target_values', 'title']], $this->audits('notice.updated')[0]->details);

        // 取り消すと、日時が来ても何も送られない
        $this->actingAs($this->representative)->deleteJson($this->url("/{$id}"))->assertNoContent();
        $this->travel(3)->hours();
        $this->runCommand('notices:publish-due');
        $this->assertSame(NoticeStatus::Cancelled, Notice::findOrFail($id)->status);
        $this->assertSame([], $this->noticeRequests());
        $this->assertCount(1, $this->audits('notice.cancelled'));
        $this->actingAs($this->representative)->patchJson($this->url("/{$id}"), ['title' => 'x'])->assertStatus(409);
    }

    public function test_AC_005_10_the_scheduled_list_shows_all_to_representatives_and_officers_and_own_to_coaches(): void
    {
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $officer = $this->member([TeamRole::Officer], '役員');
        $this->schedule(30, '代表の予約');
        $this->actingAs($coach)->postJson($this->url(), $this->input(['title' => 'コーチの予約', 'scheduled_at' => now()->addMinutes(20)->toIso8601String()]))->assertCreated();

        $titles = fn ($user) => array_column((array) $this->actingAs($user)->getJson($this->url('/scheduled'))->assertOk()->json('notices'), 'title');

        $this->assertSame(['コーチの予約', '代表の予約'], $titles($officer));
        $this->assertSame(['コーチの予約'], $titles($coach));
        $this->actingAs($this->member([TeamRole::Parent], '保護者'))->getJson($this->url('/scheduled'))->assertForbidden();
    }

    public function test_AC_005_11_a_due_notice_is_published_within_a_minute_by_the_scheduler(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $id = $this->schedule(10);

        $this->travel(10)->minutes();
        $this->runCommand('notices:publish-due');
        $this->runCommand('notices:publish-due'); // 2回動いても二重に公開しない

        $notice = Notice::findOrFail($id);
        $this->assertSame(NoticeStatus::Published, $notice->status);
        $this->assertNotNull($notice->published_at);
        $this->assertCount(1, $this->noticeRequests());
        $this->actingAs($parent)->getJson($this->url("/{$id}"))->assertOk();
        $log = $this->audits('notice.published')[0];
        $this->assertNull($log->actor_user_id);
        $this->assertSame(true, $log->details['scheduled'] ?? null);

        $events = collect(app(Schedule::class)->events());
        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e->command, 'notices:publish-due') && $e->expression === '* * * * *'));
    }

    public function test_AC_005_11_the_scheduler_queues_one_publishing_job_per_due_notice(): void
    {
        // ADR-0024:scheduler は積むだけで、公開は worker が並列に行う
        Queue::fake([PublishScheduledNoticeJob::class]);
        $due1 = $this->schedule(10, '1件目');
        $due2 = $this->schedule(10, '2件目');
        $later = $this->schedule(30, 'あと');

        $this->travel(10)->minutes();
        $this->runCommand('notices:publish-due');

        Queue::assertPushed(PublishScheduledNoticeJob::class, 2);
        Queue::assertPushed(PublishScheduledNoticeJob::class, fn (PublishScheduledNoticeJob $job) => in_array($job->noticeId, [$due1, $due2], true));
        Queue::assertNotPushed(PublishScheduledNoticeJob::class, fn (PublishScheduledNoticeJob $job) => $job->noticeId === $later);
        // まだ公開されていない(worker が動くまで)
        $this->assertSame(NoticeStatus::Scheduled, Notice::findOrFail($due1)->status);
    }

    public function test_AC_005_11_a_notice_waiting_in_the_queue_is_not_queued_again_the_next_minute(): void
    {
        Queue::fake([PublishScheduledNoticeJob::class]);
        $this->schedule(10);

        $this->travel(10)->minutes();
        $this->runCommand('notices:publish-due');
        $this->travel(1)->minutes();
        $this->runCommand('notices:publish-due');

        Queue::assertPushed(PublishScheduledNoticeJob::class, 1);
    }

    public function test_AC_005_11_running_the_publishing_job_twice_publishes_and_mails_once(): void
    {
        $this->member([TeamRole::Parent], '保護者');
        $id = $this->schedule(10);
        $this->travel(10)->minutes();

        (new PublishScheduledNoticeJob($id))->handle(app(PublishScheduledNotice::class));
        (new PublishScheduledNoticeJob($id))->handle(app(PublishScheduledNotice::class));

        $this->assertSame(NoticeStatus::Published, Notice::findOrFail($id)->status);
        $this->assertCount(1, $this->noticeRequests());
    }

    public function test_AC_005_12_recipients_are_decided_by_the_roster_at_publishing_time(): void
    {
        $id = $this->schedule(10, '小3の連絡', ['type' => 'grades', 'values' => ['e3']]);
        // 予約したあとに小3の保護者が増えた
        $newParent = $this->member([TeamRole::Parent], '新しい保護者');
        $this->child(Grade::E3, $newParent);

        $this->travel(11)->minutes();
        $this->runCommand('notices:publish-due');

        $this->assertContains($newParent->id, NoticeRecipient::where('notice_id', $id)->pluck('user_id')->all());
        $this->assertSame(2, Notice::findOrFail($id)->recipient_count);
    }

    public function test_AC_005_13_only_5_minutes_to_30_days_ahead_can_be_scheduled(): void
    {
        $message = '予約できるのは、5分後から30日後までです。';
        foreach ([now()->addMinutes(4), now()->subMinute(), now()->addDays(30)->addMinute()] as $at) {
            $this->actingAs($this->representative)->postJson($this->url(), $this->input(['scheduled_at' => $at->toIso8601String()]))
                ->assertUnprocessable()->assertJsonPath('errors.scheduled_at.0', $message);
        }
        $this->actingAs($this->representative)->postJson($this->url(), $this->input(['scheduled_at' => now()->addMinutes(6)->toIso8601String()]))->assertCreated();
        $this->actingAs($this->representative)->postJson($this->url(), $this->input(['scheduled_at' => now()->addDays(29)->toIso8601String()]))->assertCreated();
        $this->actingAs($this->representative)->postJson($this->url(), $this->input(['scheduled_at' => 'あした']))->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);

        // 編集で日時を変えるときも同じ
        $id = $this->schedule(60);
        $this->actingAs($this->representative)->patchJson($this->url("/{$id}"), ['scheduled_at' => now()->addMinute()->toIso8601String()])
            ->assertUnprocessable()->assertJsonPath('errors.scheduled_at.0', $message);
    }

    public function test_AC_005_09_AC_005_11_a_time_with_an_offset_is_stored_in_utc_and_published_at_that_moment(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08T12:00:00Z'));
        // 日本時間の翌朝7時 = UTC の 22時
        $id = (int) $this->actingAs($this->representative)
            ->postJson($this->url(), $this->input(['scheduled_at' => '2026-11-09T07:00:00+09:00']))
            ->assertCreated()
            ->assertJsonPath('notice.scheduled_at', '2026-11-08T22:00:00+00:00')
            ->json('notice.id');
        $this->assertSame('2026-11-08 22:00:00', DB::table('notices')->where('id', $id)->value('scheduled_at'));

        $this->travelTo(Carbon::parse('2026-11-08T21:59:00Z'));
        $this->runCommand('notices:publish-due');
        $this->assertSame(NoticeStatus::Scheduled, Notice::findOrFail($id)->status);

        $this->travelTo(Carbon::parse('2026-11-08T22:00:30Z'));
        $this->runCommand('notices:publish-due');
        $this->assertSame(NoticeStatus::Published, Notice::findOrFail($id)->status);

        // 編集でも同じく UTC に直す
        $other = $this->schedule(60);
        $this->actingAs($this->representative)->patchJson($this->url("/{$other}"), ['scheduled_at' => '2026-11-09T09:30:00+09:00'])
            ->assertOk()
            ->assertJsonPath('notice.scheduled_at', '2026-11-09T00:30:00+00:00');
    }

    public function test_AC_005_11_a_failing_notice_does_not_stop_the_others(): void
    {
        $first = $this->schedule(10, '先の予約');
        $second = $this->schedule(20, '後の予約');
        // 先の予約の宛先の種類を読めない値にして、公開を失敗させる
        DB::table('notices')->where('id', $first)->update(['target_type' => 'broken']);
        $log = Log::spy();

        $this->travel(1)->hours();
        $this->runCommand('notices:publish-due');

        $this->assertSame('scheduled', DB::table('notices')->where('id', $first)->value('status'));
        $this->assertSame(NoticeStatus::Published, Notice::findOrFail($second)->status);
        $log->shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['notice_id'] === $first && ! str_contains(json_encode($context) ?: '', '先の予約'));
    }

    public function test_AC_005_02_the_count_for_editing_uses_the_author_as_the_sender(): void
    {
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $otherCoach = $this->member([TeamRole::Coach], '別のコーチ');
        $id = (int) $this->actingAs($coach)->postJson($this->url(), $this->input(['scheduled_at' => now()->addHour()->toIso8601String()]))->json('notice.id');
        $target = ['type' => 'roles', 'values' => ['coach']];

        // 代表が予約を指定して数えると、送信者は作成者のコーチ。コーチ2人だけ
        $this->actingAs($this->representative)->postJson($this->url('/recipient-count'), ['target' => $target, 'notice_id' => $id])->assertOk()->assertJsonPath('count', 2);
        // 予約を指定しなければ、数える人(代表)が送信者。コーチ2人 + 代表
        $this->actingAs($this->representative)->postJson($this->url('/recipient-count'), ['target' => $target])->assertOk()->assertJsonPath('count', 3);
        // 扱えない予約は 403、別チームや無い予約は 404
        $this->actingAs($otherCoach)->postJson($this->url('/recipient-count'), ['target' => $target, 'notice_id' => $id])->assertForbidden();
        $this->actingAs($coach)->postJson($this->url('/recipient-count'), ['target' => $target, 'notice_id' => 999999])->assertNotFound();

        // 公開すると、数えた人数と同じ
        $this->actingAs($this->representative)->patchJson($this->url("/{$id}"), ['target' => $target])->assertOk();
        $this->travel(2)->hours();
        $this->runCommand('notices:publish-due');
        $this->assertSame(2, Notice::findOrFail($id)->recipient_count);
    }
}
