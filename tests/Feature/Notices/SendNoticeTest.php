<?php

namespace Tests\Feature\Notices;

use App\Enums\Grade;
use App\Enums\NoticeStatus;
use App\Enums\OutboxStatus;
use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * お知らせの作成と今すぐ送信(AC-005-01〜08)。
 */
class SendNoticeTest extends NoticeTestCase
{
    public function test_AC_005_01_a_representative_officer_or_coach_sends_a_notice_now(): void
    {
        $officer = $this->member([TeamRole::Officer], '役員');
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $this->member([TeamRole::Parent], '保護者');

        foreach ([$this->representative, $officer, $coach] as $sender) {
            $this->actingAs($sender)->postJson($this->url(), $this->input())
                ->assertCreated()
                ->assertJsonPath('notice.status', 'published')
                ->assertJsonPath('notice.recipient_count', 4)
                ->assertJsonPath('notice.title', '来週の試合');
        }

        $this->assertSame(3, Notice::where('status', NoticeStatus::Published)->count());
        $this->assertCount(3, $this->deliveredTo());
        $this->assertCount(4, $this->deliveredTo()[0]);
        $logs = $this->audits('notice.published');
        $this->assertCount(3, $logs);
        $this->assertEquals(['target' => 'all', 'values' => [], 'recipients' => 4, 'scheduled' => false], $logs[0]->details);
    }

    public function test_AC_005_01_title_and_body_limits(): void
    {
        foreach ([['title' => ''], ['title' => str_repeat('あ', 101)], ['title' => "改行\nあり"], ['body' => ''], ['body' => str_repeat('あ', 2001)], ['body' => "制御\x07文字"],
            // 文字の向きを変える書式文字・ゼロ幅文字(ADR-0017)
            ['body' => "向き\u{202E}を変える"], ['body' => "見えない\u{200B}空白"], ['body' => "途中\u{FEFF}の文字"], ['title' => "件名\u{2066}隔離"]] as $invalid) {
            $this->actingAs($this->representative)->postJson($this->url(), $this->input($invalid))->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        }
        $this->actingAs($this->representative)->postJson($this->url(), $this->input(['title' => str_repeat('あ', 100), 'body' => str_repeat("あ\n", 1000)]))->assertCreated();
        // 絵文字をつなぐ ZWJ は使える
        $this->actingAs($this->representative)->postJson($this->url(), $this->input(['body' => "家族で応援 \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"]))->assertCreated();
        $this->assertSame(2, Notice::count());
    }

    public function test_AC_005_01_target_rules(): void
    {
        foreach ([
            ['type' => 'all', 'values' => ['e1']],
            ['type' => 'grades', 'values' => []],
            ['type' => 'grades', 'values' => ['e7']],
            ['type' => 'roles', 'values' => ['captain']],
            ['type' => 'roles', 'values' => ['e1']],
            ['type' => 'both', 'values' => []],
        ] as $target) {
            $this->actingAs($this->representative)->postJson($this->url(), $this->input(['target' => $target]))->assertUnprocessable();
        }
        $this->assertSame(0, Notice::count());
    }

    public function test_AC_005_02_the_recipient_count_matches_what_is_sent(): void
    {
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $parentA = $this->member([TeamRole::Parent], '保護者A');
        $this->member([TeamRole::Parent], '保護者B');
        $this->child(Grade::E3, $parentA);

        $count = fn (array $target) => $this->actingAs($coach)->postJson($this->url('/recipient-count'), ['target' => $target])->assertOk()->json('count');

        $this->assertSame(4, $count(['type' => 'all', 'values' => []]));
        // 小3の保護者A + 代表・コーチ
        $this->assertSame(3, $count(['type' => 'grades', 'values' => ['e3']]));
        // 該当する保護者がいなくても、代表・役員・コーチと送信者本人には届く
        $this->assertSame(2, $count(['type' => 'grades', 'values' => ['j3']]));
        // 保護者2人 + 送信者本人(コーチ)
        $this->assertSame(3, $count(['type' => 'roles', 'values' => ['parent']]));

        $this->actingAs($coach)->postJson($this->url(), $this->input(['target' => ['type' => 'grades', 'values' => ['e3']]]))->assertJsonPath('notice.recipient_count', 3);
    }

    public function test_AC_005_03_grade_notices_reach_the_parents_of_those_grades_and_all_staff_once_each(): void
    {
        $officer = $this->member([TeamRole::Officer], '役員');
        $coachParent = $this->member([TeamRole::Coach, TeamRole::Parent], 'コーチ兼保護者');
        $siblingsParent = $this->member([TeamRole::Parent], 'きょうだいの保護者');
        $otherGradeParent = $this->member([TeamRole::Parent], '別の学年の保護者');
        $this->child(Grade::E3, $siblingsParent, $coachParent);
        $this->child(Grade::E2, $siblingsParent);
        $this->child(Grade::E5, $otherGradeParent);

        $id = (int) $this->actingAs($officer)->postJson($this->url(), $this->input(['target' => ['type' => 'grades', 'values' => ['e2', 'e3']]]))->assertCreated()->json('notice.id');

        $recipients = NoticeRecipient::where('notice_id', $id)->pluck('user_id')->sort()->values()->all();
        $expected = collect([$this->representative->id, $officer->id, $coachParent->id, $siblingsParent->id])->sort()->values()->all();
        $this->assertSame($expected, $recipients);
        $this->assertNotContains($otherGradeParent->id, $recipients);
        $sent = $this->deliveredTo()[0];
        $this->assertCount(4, $sent);
        $this->assertSame(count($sent), count(array_unique($sent)));
    }

    public function test_AC_005_03_role_notices_reach_only_those_roles_and_the_sender(): void
    {
        $coach = $this->member([TeamRole::Coach], 'コーチ');
        $parent = $this->member([TeamRole::Parent], '保護者');
        $this->member([TeamRole::Officer], '役員');

        $id = (int) $this->actingAs($this->representative)->postJson($this->url(), $this->input(['target' => ['type' => 'roles', 'values' => ['coach', 'parent']]]))->json('notice.id');

        $this->assertEqualsCanonicalizing([$coach->id, $parent->id, $this->representative->id], NoticeRecipient::where('notice_id', $id)->pluck('user_id')->all());
    }

    public function test_AC_005_04_a_parent_cannot_create_a_notice(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');

        $this->actingAs($parent)->postJson($this->url(), $this->input())->assertForbidden();
        $this->actingAs($parent)->postJson($this->url(), [])->assertForbidden();
        $this->actingAs($parent)->postJson($this->url('/recipient-count'), ['target' => ['type' => 'all', 'values' => []]])->assertForbidden();
        $this->assertSame(0, Notice::count());
    }

    public function test_AC_005_05_the_mail_has_team_name_and_title_in_the_subject_and_the_body_and_a_link(): void
    {
        $id = (int) $this->actingAs($this->representative)->postJson($this->url(), $this->input())->json('notice.id');

        $requests = $this->noticeRequests();
        $this->assertCount(1, $requests);
        $this->assertSame("team:{$this->team->id}:notice:{$id}:part:1", $requests[0]['dedupe_key'] ?? null);
        $message = is_array($requests[0]['message'] ?? null) ? $requests[0]['message'] : [];
        $this->assertSame('【つくばFC】来週の試合', $message['subject'] ?? null);
        $this->assertSame("集合は8時です。\n詳しくは https://example.invalid/match を見てください。", $message['text'] ?? null);
        $this->assertSame(config('team_ops.web_url')."/teams/{$this->team->id}/notices/{$id}", $message['url'] ?? null);
    }

    public function test_AC_005_06_a_published_notice_cannot_be_edited_or_cancelled(): void
    {
        $id = (int) $this->actingAs($this->representative)->postJson($this->url(), $this->input())->json('notice.id');

        $this->actingAs($this->representative)->patchJson($this->url("/{$id}"), ['title' => '訂正'])
            ->assertStatus(409)
            ->assertJsonPath('errors.notice.0', '送信済みのお知らせは編集・取り消しできません。訂正は新しいお知らせで送ってください。');
        $this->actingAs($this->representative)->deleteJson($this->url("/{$id}"))->assertStatus(409);
        $this->assertSame('来週の試合', Notice::findOrFail($id)->title);
    }

    public function test_AC_005_07_when_notify_relay_is_down_the_notice_is_published_and_mail_is_sent_after_recovery_once(): void
    {
        $this->relayUp = false;
        $this->member([TeamRole::Parent], '保護者');

        $id = (int) $this->actingAs($this->representative)->postJson($this->url(), $this->input())->assertCreated()->json('notice.id');

        $this->assertSame(NoticeStatus::Published, Notice::findOrFail($id)->status);
        $mail = OutboxMessage::where('kind', 'notice')->sole();
        $this->assertSame(OutboxStatus::Pending, $mail->status);

        $this->assertSame([], $this->noticeRequests());

        // 復旧したあとの送り直し(毎分の outbox:redispatch)。2回動いても1回だけ送る
        $this->relayUp = true;
        $this->travel(1)->hours();
        $this->runCommand('outbox:redispatch');
        $this->runCommand('outbox:redispatch');

        $this->assertSame(OutboxStatus::Sent, $mail->fresh()?->status);
        $this->assertCount(1, $this->noticeRequests());
        $this->assertCount(2, $this->deliveredTo()[0]);
        $this->assertSame(1, OutboxMessage::where('kind', 'notice')->count());
    }

    public function test_AC_005_08_more_than_1000_recipients_are_split_without_duplicates(): void
    {
        $now = now();
        $ids = User::factory()->count(1500)->sequence(fn ($seq) => ['email' => "member{$seq->index}@example.invalid"])->create()->pluck('id')->all();
        DB::table('team_memberships')->insert(array_map(fn (int $id) => ['team_id' => $this->team->id, 'user_id' => $id, 'created_at' => $now, 'updated_at' => $now], $ids));

        $this->actingAs($this->representative)->postJson($this->url(), $this->input())->assertCreated()->assertJsonPath('notice.recipient_count', 1501);

        $parts = $this->deliveredTo();
        $this->assertSame([1000, 501], array_map('count', $parts));
        $all = array_merge(...$parts);
        $this->assertSame(1501, count(array_unique($all)));
        $this->assertSame(
            ['part:1', 'part:2'],
            array_map(fn (array $r) => substr((string) $r['dedupe_key'], (int) strrpos((string) $r['dedupe_key'], 'part:')), $this->noticeRequests()),
        );
    }

    public function test_the_title_and_body_are_stored_encrypted(): void
    {
        $id = (int) $this->actingAs($this->representative)->postJson($this->url(), $this->input())->json('notice.id');

        $row = (array) DB::table('notices')->where('id', $id)->first();
        $this->assertStringNotContainsString('来週の試合', implode(' ', array_map('strval', $row)));
        $this->assertStringNotContainsString('集合', implode(' ', array_map('strval', $row)));
        $this->assertStringNotContainsString('来週', (string) json_encode(AuditLog::pluck('details')->all(), JSON_UNESCAPED_UNICODE));
    }
}
