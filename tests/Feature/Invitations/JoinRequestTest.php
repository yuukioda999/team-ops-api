<?php

namespace Tests\Feature\Invitations;

use App\Enums\JoinRequestStatus;
use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 002 参加申請と承認(AC-002-06・09〜17)。
 */
class JoinRequestTest extends InvitationTestCase
{
    public function test_AC_002_06_an_application_waits_for_approval_and_notifies_the_reviewers(): void
    {
        $this->member([TeamRole::Officer], 'officer@example.invalid');
        $this->member([TeamRole::Coach], 'coach@example.invalid');
        $token = $this->issueLink();
        $parent = $this->applicant();

        $this->actingAs($parent)->postJson('/api/invitations/applications', $this->application($token))
            ->assertCreated()
            ->assertExactJson(['status' => 'pending', 'team' => ['name' => 'つくばFC U-10']]);

        $request = TeamJoinRequest::sole();
        $this->assertSame(JoinRequestStatus::Pending, $request->status);
        $this->assertSame('3年 山田太郎の母です', $request->message);
        $this->assertStringNotContainsString('山田太郎', (string) $request->getRawOriginal('message')); // 暗号化して保存
        $this->assertSame('山田 花子', $parent->fresh()?->display_name); // アカウントの表示名になる(判断5)

        // 代表と役員に知らせる。コーチには送らない。メールに申請者の名前もひとことも書かない
        $mail = $this->latestMail('join_request_submitted');
        $this->assertNotNull($mail);
        $this->assertEqualsCanonicalizing(['rep@example.invalid', 'officer@example.invalid'], $this->recipientsOf($mail));
        $this->assertSame("http://localhost:5173/teams/{$this->team->id}/join-requests", $this->messageOf($mail)['url']);
        $this->assertStringNotContainsString('山田', json_encode($this->messageOf($mail), JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_AC_002_06_the_message_is_optional_and_at_most_100_characters(): void
    {
        $token = $this->issueLink();

        $this->actingAs($this->applicant())->postJson('/api/invitations/applications', $this->application($token, message: str_repeat('あ', 101)))
            ->assertUnprocessable()->assertJsonValidationErrors(['message']);
        $this->actingAs($this->applicant('b@example.invalid'))->postJson('/api/invitations/applications', $this->application($token, displayName: ' '))
            ->assertUnprocessable()->assertJsonValidationErrors(['display_name']);
        $this->actingAs($this->applicant('c@example.invalid'))->postJson('/api/invitations/applications', $this->application($token, message: null))
            ->assertCreated();
    }

    public function test_AC_002_09_applying_again_while_pending_does_not_create_another(): void
    {
        $token = $this->issueLink();
        $parent = $this->applicant();
        $this->actingAs($parent)->postJson('/api/invitations/applications', $this->application($token))->assertCreated();

        $this->actingAs($parent)->postJson('/api/invitations/applications', $this->application($token))
            ->assertOk()
            ->assertJsonPath('status', 'pending');
        $this->assertSame(1, TeamJoinRequest::count());
        $this->assertSame(1, OutboxMessage::where('kind', 'join_request_submitted')->count());

        $this->actingAs($parent)->postJson('/api/invitations/preview', ['token' => $token])->assertJsonPath('relation', 'pending');
        $this->actingAs($parent)->getJson('/api/me')->assertJsonPath('join_requests.0.status', 'pending')
            ->assertJsonPath('join_requests.0.team_name', 'つくばFC U-10');
    }

    public function test_AC_002_10_more_than_50_applications_a_day_on_one_link_are_refused_and_the_representative_is_told(): void
    {
        $token = $this->issueLink();
        for ($i = 1; $i <= 50; $i++) {
            $this->actingAs($this->applicant("p{$i}@example.invalid"))->postJson('/api/invitations/applications', $this->application($token))->assertCreated();
        }

        $this->actingAs($this->applicant('p51@example.invalid'))->postJson('/api/invitations/applications', $this->application($token))->assertTooManyRequests();
        $this->actingAs($this->applicant('p52@example.invalid'))->postJson('/api/invitations/applications', $this->application($token))->assertTooManyRequests();
        $this->assertSame(50, TeamJoinRequest::count());

        // 代表へのメールは1日1通
        $floods = OutboxMessage::where('kind', 'invitation_flood')->get();
        $this->assertCount(1, $floods);
        $this->assertSame(['rep@example.invalid'], $this->recipientsOf($floods->sole()));

        // 24時間たてば、また受け付ける
        $this->travel(25)->hours();
        $this->actingAs($this->applicant('p53@example.invalid'))->postJson('/api/invitations/applications', $this->application($token))->assertCreated();
    }

    public function test_AC_002_11_reviewers_see_the_name_message_and_time_of_pending_applications(): void
    {
        $this->freezeTime();
        $token = $this->issueLink();
        $this->actingAs($this->applicant())->postJson('/api/invitations/applications', $this->application($token))->assertCreated();

        $this->actingAs($this->representative)->getJson("/api/teams/{$this->team->id}/join-requests")
            ->assertOk()
            ->assertJsonPath('join_requests.0.display_name', '山田 花子')
            ->assertJsonPath('join_requests.0.message', '3年 山田太郎の母です')
            ->assertJsonPath('join_requests.0.created_at', now()->toIso8601String());
    }

    /**
     * @return array<string, array{list<TeamRole>, int}>
     */
    public static function reviewerRoles(): array
    {
        return [
            'officer' => [[TeamRole::Officer], 200],
            'coach' => [[TeamRole::Coach], 403],
            'parent' => [[TeamRole::Parent], 403],
        ];
    }

    /**
     * @param  list<TeamRole>  $roles
     */
    #[DataProvider('reviewerRoles')]
    public function test_AC_002_11_only_the_representative_and_officers_review_applications(array $roles, int $status): void
    {
        $token = $this->issueLink();
        $this->actingAs($this->applicant())->postJson('/api/invitations/applications', $this->application($token))->assertCreated();
        $id = TeamJoinRequest::sole()->id;
        $member = $this->member($roles);

        $this->actingAs($member)->getJson("/api/teams/{$this->team->id}/join-requests")->assertStatus($status);
        $this->actingAs($member)->postJson("/api/teams/{$this->team->id}/join-requests/{$id}/reject")->assertStatus($status === 200 ? 204 : 403);
    }

    /**
     * @return array<string, array{list<string>, list<TeamRole>}>
     */
    public static function grantedRoles(): array
    {
        return [
            'parent' => [['parent'], [TeamRole::Parent]],
            'coach' => [['coach'], [TeamRole::Coach]],
            'coach and parent' => [['parent', 'coach'], [TeamRole::Coach, TeamRole::Parent]],
        ];
    }

    /**
     * @param  list<string>  $send
     * @param  list<TeamRole>  $expected
     */
    #[DataProvider('grantedRoles')]
    public function test_AC_002_12_approving_makes_the_applicant_a_member_with_the_chosen_roles(array $send, array $expected): void
    {
        $token = $this->issueLink();
        $parent = $this->applicant();
        $this->actingAs($parent)->postJson('/api/invitations/applications', $this->application($token))->assertCreated();
        $request = TeamJoinRequest::sole();

        $this->actingAs($this->representative)->postJson("/api/teams/{$this->team->id}/join-requests/{$request->id}/approve", ['roles' => $send])
            ->assertNoContent();

        $membership = TeamMembership::where('team_id', $this->team->id)->where('user_id', $parent->id)->sole();
        $this->assertSame($expected, $membership->roleList());
        $this->actingAs($parent)->getJson("/api/teams/{$this->team->id}")->assertOk()
            ->assertJsonPath('roles', array_map(fn (TeamRole $r) => $r->value, $expected));

        $request->refresh();
        $this->assertSame(JoinRequestStatus::Approved, $request->status);
        $this->assertNull($request->message); // AC-002-17

        $mail = $this->latestMail('join_request_approved');
        $this->assertNotNull($mail);
        $this->assertSame(['parent@example.invalid'], $this->recipientsOf($mail));
        $this->assertSame("http://localhost:5173/teams/{$this->team->id}", $this->messageOf($mail)['url']);

        $log = AuditLog::where('action', 'join_request.approved')->sole();
        // MySQL の JSON 列はキーを並べ替えて保存するので、キーの順番には依存しない
        $this->assertEquals(['user_id' => $parent->id, 'roles' => array_map(fn (TeamRole $r) => $r->value, $send === ['parent', 'coach'] ? [TeamRole::Parent, TeamRole::Coach] : $expected)], $log->details);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rolesNotGrantable(): array
    {
        return [
            'officer' => [['officer']],
            'representative' => [['representative']],
            'parent and officer' => [['parent', 'officer']],
            'none' => [[]],
            'unknown' => [['captain']],
        ];
    }

    #[DataProvider('rolesNotGrantable')]
    public function test_AC_002_14_officer_and_representative_cannot_be_chosen_on_approval(mixed $roles): void
    {
        $token = $this->issueLink();
        $this->actingAs($this->applicant())->postJson('/api/invitations/applications', $this->application($token))->assertCreated();
        $id = TeamJoinRequest::sole()->id;
        $officer = $this->member([TeamRole::Officer], 'officer@example.invalid');

        $this->actingAs($officer)->postJson("/api/teams/{$this->team->id}/join-requests/{$id}/approve", ['roles' => $roles])
            ->assertUnprocessable();
        $this->assertSame(JoinRequestStatus::Pending, TeamJoinRequest::sole()->status);
    }

    public function test_AC_002_13_rejecting_sends_nothing_and_the_applicant_sees_it_was_not_approved(): void
    {
        $token = $this->issueLink();
        $parent = $this->applicant();
        $this->actingAs($parent)->postJson('/api/invitations/applications', $this->application($token))->assertCreated();
        $request = TeamJoinRequest::sole();
        $mailsBefore = OutboxMessage::count();

        $this->actingAs($this->representative)->postJson("/api/teams/{$this->team->id}/join-requests/{$request->id}/reject")->assertNoContent();

        $this->assertSame($mailsBefore, OutboxMessage::count());
        $this->assertFalse(TeamMembership::where('team_id', $this->team->id)->where('user_id', $parent->id)->exists());
        $this->assertNull($request->fresh()?->message); // AC-002-17
        $this->actingAs($parent)->getJson('/api/me')->assertJsonPath('join_requests.0.status', 'rejected');
        $this->actingAs($parent)->getJson("/api/teams/{$this->team->id}")->assertNotFound();
        $this->assertSame(['user_id' => $parent->id], AuditLog::where('action', 'join_request.rejected')->sole()->details);
    }

    public function test_AC_002_13_a_decided_application_cannot_be_decided_again(): void
    {
        $token = $this->issueLink();
        $this->actingAs($this->applicant())->postJson('/api/invitations/applications', $this->application($token))->assertCreated();
        $id = TeamJoinRequest::sole()->id;
        $this->actingAs($this->representative)->postJson("/api/teams/{$this->team->id}/join-requests/{$id}/reject")->assertNoContent();

        $this->actingAs($this->representative)->postJson("/api/teams/{$this->team->id}/join-requests/{$id}/approve", ['roles' => ['parent']])->assertNotFound();
        $this->actingAs($this->representative)->postJson("/api/teams/{$this->team->id}/join-requests/{$id}/reject")->assertNotFound();
    }

    public function test_AC_002_15_applications_left_for_14_days_are_cancelled(): void
    {
        $token = $this->issueLink();
        $parent = $this->applicant();
        $this->actingAs($parent)->postJson('/api/invitations/applications', $this->application($token))->assertCreated();

        $this->travel(14)->days();
        $this->travel(-1)->minutes();
        Artisan::call('join-requests:expire');
        $this->assertSame(JoinRequestStatus::Pending, TeamJoinRequest::sole()->status);

        $this->travel(2)->minutes();
        Artisan::call('join-requests:expire');
        $request = TeamJoinRequest::sole();
        $this->assertSame(JoinRequestStatus::Cancelled, $request->status);
        $this->assertNull($request->message); // AC-002-17
        $this->actingAs($parent)->getJson('/api/me')->assertJsonPath('join_requests.0.status', 'cancelled');
    }

    public function test_AC_002_15_expiry_is_scheduled_daily(): void
    {
        Artisan::call('schedule:list');
        $this->assertStringContainsString('join-requests:expire', Artisan::output());
    }

    public function test_AC_002_16_a_rejected_applicant_can_apply_again(): void
    {
        $token = $this->issueLink();
        $parent = $this->applicant();
        $this->actingAs($parent)->postJson('/api/invitations/applications', $this->application($token))->assertCreated();
        $first = TeamJoinRequest::sole();
        $this->actingAs($this->representative)->postJson("/api/teams/{$this->team->id}/join-requests/{$first->id}/reject")->assertNoContent();

        $this->actingAs($parent)->postJson('/api/invitations/applications', $this->application($token))->assertCreated();

        $this->assertSame([JoinRequestStatus::Rejected, JoinRequestStatus::Pending], TeamJoinRequest::orderBy('id')->get()->map(fn (TeamJoinRequest $r) => $r->status)->all());
    }

    public function test_AC_002_17_decided_applications_are_deleted_after_30_days(): void
    {
        $token = $this->issueLink();
        $this->actingAs($this->applicant())->postJson('/api/invitations/applications', $this->application($token))->assertCreated();
        $id = TeamJoinRequest::sole()->id;
        $this->actingAs($this->representative)->postJson("/api/teams/{$this->team->id}/join-requests/{$id}/reject")->assertNoContent();

        $this->travel(30)->days();
        $this->travel(-1)->minutes();
        Artisan::call('model:prune', ['--model' => [TeamJoinRequest::class]]);
        $this->assertSame(1, TeamJoinRequest::count());

        $this->travel(2)->minutes();
        Artisan::call('model:prune', ['--model' => [TeamJoinRequest::class]]);
        $this->assertSame(0, TeamJoinRequest::count());
    }

    public function test_AC_000_04_the_message_and_names_do_not_reach_the_logs(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });
        $token = $this->issueLink();
        $this->actingAs($this->applicant())->postJson('/api/invitations/applications', $this->application($token))->assertCreated();

        $all = implode("\n", $logged);
        $this->assertStringNotContainsString('山田', $all);
        $this->assertStringNotContainsString($token, $all);
    }
}
