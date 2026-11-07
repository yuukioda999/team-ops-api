<?php

namespace Tests\Feature\Notices;

use App\Enums\Grade;
use App\Enums\NoticeStatus;
use App\Enums\TeamRole;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use Illuminate\Console\Scheduling\Schedule;

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
}
