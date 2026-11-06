<?php

namespace Tests\Feature\Invitations;

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\TeamInvitation;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 002 招待リンクの発行・無効化(AC-002-01〜04)と、リンクを開いたときの表示(AC-002-05・07・08)。
 */
class InvitationLinkTest extends InvitationTestCase
{
    public function test_AC_002_01_a_representative_issues_an_unguessable_link_valid_for_7_days(): void
    {
        $this->freezeTime();
        $response = $this->actingAs($this->representative)->postJson("/api/teams/{$this->team->id}/invitation")->assertCreated();

        $url = $response->json('invitation.url');
        $this->assertIsString($url);
        $this->assertMatchesRegularExpression('#^http://localhost:5173/join\?token=[0-9a-f]{64}$#', $url);
        $this->assertSame(now()->addDays(7)->toIso8601String(), $response->json('invitation.expires_at'));

        // DB にはトークンそのものを平文で持たない(照合用のハッシュと、暗号化したもの)
        $invitation = TeamInvitation::sole();
        $token = $this->tokenOf($url);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertStringNotContainsString($token, (string) $invitation->getRawOriginal('token'));
    }

    public function test_AC_002_01_the_same_link_can_be_shown_again(): void
    {
        $token = $this->issueLink();

        $this->actingAs($this->representative)->getJson("/api/teams/{$this->team->id}/invitation")
            ->assertOk()
            ->assertJsonPath('invitation.url', "http://localhost:5173/join?token={$token}");
    }

    public function test_AC_002_02_a_new_link_invalidates_the_old_one(): void
    {
        $old = $this->issueLink();
        $new = $this->issueLink();

        $this->postJson('/api/invitations/preview', ['token' => $old])->assertStatus(410);
        $this->postJson('/api/invitations/preview', ['token' => $new])->assertOk();
        $this->assertSame(1, TeamInvitation::where('team_id', $this->team->id)->active()->count());
    }

    public function test_AC_002_03_a_revoked_link_cannot_be_used(): void
    {
        $token = $this->issueLink();

        $this->actingAs($this->representative)->deleteJson("/api/teams/{$this->team->id}/invitation")->assertNoContent();

        $this->postJson('/api/invitations/preview', ['token' => $token])->assertStatus(410);
        $this->actingAs($this->applicant())->postJson('/api/invitations/applications', $this->application($token))->assertStatus(410);
        $this->actingAs($this->representative)->getJson("/api/teams/{$this->team->id}/invitation")->assertJsonPath('invitation', null);
    }

    /**
     * @return array<string, array{list<TeamRole>, bool}>
     */
    public static function roles(): array
    {
        return [
            'officer' => [[TeamRole::Officer], true],
            'coach' => [[TeamRole::Coach], false],
            'parent' => [[TeamRole::Parent], false],
            'coach and parent' => [[TeamRole::Coach, TeamRole::Parent], false],
        ];
    }

    /**
     * @param  list<TeamRole>  $roles
     */
    #[DataProvider('roles')]
    public function test_AC_002_04_only_the_representative_and_officers_manage_the_link(array $roles, bool $allowed): void
    {
        $member = $this->member($roles);
        $status = $allowed ? 201 : 403;

        $this->actingAs($member)->postJson("/api/teams/{$this->team->id}/invitation")->assertStatus($status);
        $this->actingAs($member)->getJson("/api/teams/{$this->team->id}/invitation")->assertStatus($allowed ? 200 : 403);
        $this->actingAs($member)->deleteJson("/api/teams/{$this->team->id}/invitation")->assertStatus($allowed ? 204 : 403);
        $this->actingAs($member)->getJson("/api/teams/{$this->team->id}")
            ->assertJsonPath('permissions.manage_invitation', $allowed);
    }

    public function test_AC_002_05_anyone_with_the_link_sees_only_the_team_name(): void
    {
        $token = $this->issueLink();
        $this->app->make('auth')->forgetGuards();

        $this->postJson('/api/invitations/preview', ['token' => $token])
            ->assertOk()
            ->assertExactJson(['team' => ['name' => 'つくばFC U-10', 'sport' => 'soccer'], 'relation' => null, 'team_id' => null]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTokens(): array
    {
        return [
            'unknown' => [str_repeat('a', 64)],
            'malformed' => ['not-a-token'],
        ];
    }

    #[DataProvider('invalidTokens')]
    public function test_AC_002_07_an_unknown_link_says_the_same_as_an_expired_one(string $token): void
    {
        $expired = $this->issueLink();
        $this->travel(7)->days();
        $this->travel(1)->second();

        $expiredResponse = $this->postJson('/api/invitations/preview', ['token' => $expired])->assertStatus(410);
        $unknownResponse = $this->postJson('/api/invitations/preview', ['token' => $token])->assertStatus(410);

        $this->assertSame('リンクが無効です。チームの役員に新しいリンクを依頼してください', $expiredResponse->json('message'));
        $this->assertSame($expiredResponse->getContent(), $unknownResponse->getContent());
    }

    public function test_AC_002_08_a_member_opening_the_link_is_told_where_the_team_is(): void
    {
        $token = $this->issueLink();
        $parent = $this->member([TeamRole::Parent]);

        $this->actingAs($parent)->postJson('/api/invitations/preview', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('relation', 'member')
            ->assertJsonPath('team_id', $this->team->id);
        $this->actingAs($parent)->postJson('/api/invitations/applications', $this->application($token))
            ->assertConflict()
            ->assertJsonPath('team_id', $this->team->id);
    }

    public function test_issuing_and_revoking_are_audited_without_personal_data(): void
    {
        $this->issueLink();
        $this->issueLink();
        $this->actingAs($this->representative)->deleteJson("/api/teams/{$this->team->id}/invitation")->assertNoContent();

        $logs = AuditLog::where('team_id', $this->team->id)->orderBy('id')->get();
        $this->assertSame(['invitation.issued', 'invitation.issued', 'invitation.revoked'], $logs->pluck('action')->all());
        $this->assertSame([false, true], $logs->take(2)->map(fn (AuditLog $l) => $l->details['replaced_previous'] ?? null)->all());
        $this->assertTrue($logs->every(fn (AuditLog $l) => $l->actor_user_id === $this->representative->id));
        $this->assertStringNotContainsString('代表 太郎', $logs->toJson());
    }
}
