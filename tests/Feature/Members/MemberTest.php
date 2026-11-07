<?php

namespace Tests\Feature\Members;

use App\Enums\Grade;
use App\Enums\TeamRole;
use App\Models\Player;
use App\Models\PlayerGuardian;
use App\Models\TeamMemberRole;
use App\Models\TeamMembership;
use Illuminate\Support\Facades\DB;

/**
 * メンバー一覧、役割の変更、代表の交代、除名(AC-004-03〜10、ADR-0015)。
 */
class MemberTest extends MemberTestCase
{
    public function test_the_member_list_shows_names_and_roles_without_email(): void
    {
        $parent = $this->member([TeamRole::Parent], '山田 花子', 'hanako@example.invalid');
        $officer = $this->member([TeamRole::Officer, TeamRole::Coach], '佐藤 役員');

        $response = $this->actingAs($officer)->getJson($this->url('/members'))->assertOk()->assertJsonCount(3, 'members');

        // 代表が先頭、あとは参加した順
        $this->assertSame([
            ['membership_id' => $this->membershipOf($this->representative)->id, 'display_name' => '代表 太郎', 'roles' => ['representative'], 'is_me' => false],
            ['membership_id' => $this->membershipOf($parent)->id, 'display_name' => '山田 花子', 'roles' => ['parent'], 'is_me' => false],
            ['membership_id' => $this->membershipOf($officer)->id, 'display_name' => '佐藤 役員', 'roles' => ['officer', 'coach'], 'is_me' => true],
        ], $response->json('members'));
        $this->assertStringNotContainsString('@', (string) $response->getContent());
    }

    public function test_AC_004_03_the_representative_changes_roles_and_the_member_is_notified_and_audited(): void
    {
        $parent = $this->member([TeamRole::Parent], '山田 花子', 'hanako@example.invalid');
        $id = $this->membershipOf($parent)->id;

        $this->actingAs($this->representative)->putJson($this->url("/members/{$id}/roles"), ['roles' => ['coach', 'parent']])
            ->assertOk()
            ->assertJsonPath('member.roles', ['coach', 'parent']);

        $this->assertSame(['coach', 'parent'], $this->rolesOf($parent));
        $mails = $this->mails('member_roles_changed');
        $this->assertCount(1, $mails);
        $this->assertSame('hanako@example.invalid', $mails[0]['to']);
        $this->assertSame('【チーム運営】役割が変更されました', $mails[0]['subject']);
        $this->assertStringContainsString('「つくばFC U-10」でのあなたの役割が変更されました。あなたの役割は「コーチ・保護者」になりました。', $mails[0]['text']);

        $logs = $this->audits('member.roles_changed');
        $this->assertCount(1, $logs);
        $this->assertSame($this->representative->id, $logs[0]->actor_user_id);
        $this->assertSame($id, $logs[0]->subject_id);
        $this->assertEquals(['user_id' => $parent->id, 'before' => ['parent'], 'after' => ['coach', 'parent'], 'guardians_unlinked' => 0], $logs[0]->details);
    }

    public function test_AC_004_03_appointing_and_dismissing_an_officer(): void
    {
        $coach = $this->member([TeamRole::Coach]);
        $id = $this->membershipOf($coach)->id;

        $this->actingAs($this->representative)->putJson($this->url("/members/{$id}/roles"), ['roles' => ['officer', 'coach']])->assertOk();
        $this->assertSame(['officer', 'coach'], $this->rolesOf($coach));
        $this->actingAs($this->representative)->putJson($this->url("/members/{$id}/roles"), ['roles' => ['coach']])->assertOk();
        $this->assertSame(['coach'], $this->rolesOf($coach));
        $this->assertCount(2, $this->mails('member_roles_changed'));
    }

    public function test_AC_004_03_no_change_sends_no_mail_and_no_audit(): void
    {
        $parent = $this->member([TeamRole::Parent]);

        $this->actingAs($this->representative)->putJson($this->url("/members/{$this->membershipOf($parent)->id}/roles"), ['roles' => ['parent']])->assertOk();

        $this->assertSame([], $this->mails('member_roles_changed'));
        $this->assertSame([], $this->audits('member.roles_changed'));
    }

    public function test_AC_004_03_the_representative_role_cannot_be_given_by_a_role_change(): void
    {
        $officer = $this->member([TeamRole::Officer]);

        $this->actingAs($this->representative)->putJson($this->url("/members/{$this->membershipOf($officer)->id}/roles"), ['roles' => ['representative']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['roles.0' => ['代表は役割の変更では付けられません。代表を交代する操作を使ってください。']]);
        $this->assertSame(['officer'], $this->rolesOf($officer));
        $this->assertSame(1, TeamMemberRole::where('team_id', $this->team->id)->where('role', 'representative')->count());
    }

    public function test_AC_004_06_the_representative_keeps_the_representative_role_when_changing_their_own_roles(): void
    {
        $id = $this->membershipOf($this->representative)->id;

        $this->actingAs($this->representative)->putJson($this->url("/members/{$id}/roles"), ['roles' => ['parent']])
            ->assertOk()->assertJsonPath('member.roles', ['representative', 'parent']);
        $this->actingAs($this->representative)->putJson($this->url("/members/{$id}/roles"), ['roles' => []])
            ->assertOk()->assertJsonPath('member.roles', ['representative']);
    }

    public function test_AC_004_06_the_representative_cannot_remove_themselves(): void
    {
        $this->actingAs($this->representative)->deleteJson($this->url("/members/{$this->membershipOf($this->representative)->id}"))
            ->assertUnprocessable()
            ->assertJsonPath('errors.member.0', '自分を除名することはできません。先に代表を交代してください。');

        $this->assertSame(['representative'], $this->rolesOf($this->representative));
    }

    public function test_AC_004_09_a_member_cannot_be_left_without_roles(): void
    {
        $parent = $this->member([TeamRole::Parent]);

        $this->actingAs($this->representative)->putJson($this->url("/members/{$this->membershipOf($parent)->id}/roles"), ['roles' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errors.roles.0', '役割をすべて外すと除名になります。除名する場合は、除名の操作を行ってください。');

        $this->assertSame(['parent'], $this->rolesOf($parent));
    }

    public function test_AC_004_10_a_changed_role_applies_from_the_next_request(): void
    {
        $officer = $this->member([TeamRole::Officer]);
        $this->actingAs($officer)->getJson($this->url('/members'))->assertOk();

        $this->actingAs($this->representative)->putJson($this->url("/members/{$this->membershipOf($officer)->id}/roles"), ['roles' => ['parent']])->assertOk();

        $this->actingAs($officer)->getJson($this->url('/members'))->assertForbidden();
        $this->actingAs($officer)->getJson($this->url())->assertJsonPath('roles', ['parent'])->assertJsonPath('permissions.view_members', false);
    }

    public function test_removing_the_parent_role_also_unlinks_the_member_from_players(): void
    {
        $member = $this->member([TeamRole::Parent, TeamRole::Coach]);
        $membership = $this->membershipOf($member);
        foreach (['兄', '妹'] as $name) {
            Player::create(['team_id' => $this->team->id, 'display_name' => $name, 'grade' => Grade::E3, 'number' => null])
                ->guardians()->create(['membership_id' => $membership->id]);
        }

        $this->actingAs($this->representative)->putJson($this->url("/members/{$membership->id}/roles"), ['roles' => ['coach']])->assertOk();

        $this->assertSame(0, PlayerGuardian::where('membership_id', $membership->id)->count());
        $this->assertSame(2, Player::count());
        $this->assertSame(2, $this->audits('member.roles_changed')[0]->details['guardians_unlinked'] ?? null);
    }

    public function test_AC_004_04_the_representative_hands_over_to_an_officer(): void
    {
        $this->member([TeamRole::Parent], 'x');
        $officer = $this->member([TeamRole::Officer, TeamRole::Parent], '佐藤 役員', 'officer@example.invalid');
        $this->membershipOf($this->representative)->grant([TeamRole::Coach]);

        $this->actingAs($this->representative)->postJson($this->url('/representative'), ['membership_id' => $this->membershipOf($officer)->id])
            ->assertOk()
            ->assertJsonPath('member.roles', ['representative', 'parent']);

        $this->assertSame(['representative', 'parent'], $this->rolesOf($officer));
        $this->assertSame(['officer', 'coach'], $this->rolesOf($this->representative));
        $this->assertSame(1, TeamMemberRole::where('team_id', $this->team->id)->where('role', 'representative')->count());

        $mails = $this->mails('representative_transferred');
        $this->assertEqualsCanonicalizing(['officer@example.invalid', 'rep@example.invalid'], array_column($mails, 'to'));
        $this->assertSame('【チーム運営】代表が交代しました', $mails[0]['subject']);
        $byRecipient = array_column($mails, 'text', 'to');
        $this->assertStringContainsString('あなたの役割は「代表・保護者」になりました', $byRecipient['officer@example.invalid']);
        $this->assertStringContainsString('あなたの役割は「役員・コーチ」になりました', $byRecipient['rep@example.invalid']);

        $logs = $this->audits('team.representative_transferred');
        $this->assertCount(1, $logs);
        $this->assertEquals(['from_user_id' => $this->representative->id, 'to_user_id' => $officer->id], $logs[0]->details);

        // 元の代表は役員になったので、もう代表の操作はできない
        $this->actingAs($this->representative)->getJson($this->url('/audit-logs'))->assertForbidden();
        $this->actingAs($officer)->getJson($this->url('/audit-logs'))->assertOk();
    }

    public function test_AC_004_05_handing_over_to_a_non_officer_is_rejected_with_the_reason(): void
    {
        $coach = $this->member([TeamRole::Coach, TeamRole::Parent]);

        foreach ([$coach, $this->representative] as $target) {
            $this->actingAs($this->representative)->postJson($this->url('/representative'), ['membership_id' => $this->membershipOf($target)->id])
                ->assertUnprocessable()
                ->assertJsonPath('errors.membership_id.0', '代表にできるのは役員のメンバーだけです。先に役員に任命してください。');
        }
        $this->assertSame(['representative'], $this->rolesOf($this->representative));
        $this->assertSame([], $this->mails('representative_transferred'));
    }

    public function test_AC_004_07_an_officer_cannot_remove_the_representative_or_another_officer(): void
    {
        $officer = $this->member([TeamRole::Officer]);
        $otherOfficer = $this->member([TeamRole::Officer, TeamRole::Parent]);

        foreach ([$this->representative, $otherOfficer, $officer] as $target) {
            $this->actingAs($officer)->deleteJson($this->url("/members/{$this->membershipOf($target)->id}"))->assertForbidden();
        }
        $this->assertSame(3, TeamMembership::where('team_id', $this->team->id)->count());
    }

    public function test_AC_004_08_a_removed_member_loses_access_immediately_and_their_links_are_removed(): void
    {
        $officer = $this->member([TeamRole::Officer]);
        $parent = $this->member([TeamRole::Parent, TeamRole::Coach], '山田 花子');
        $membership = $this->membershipOf($parent);
        $player = Player::create(['team_id' => $this->team->id, 'display_name' => '山田 太郎', 'grade' => Grade::E3, 'number' => null]);
        $player->guardians()->create(['membership_id' => $membership->id]);
        $this->actingAs($parent)->getJson($this->url('/players'))->assertOk();

        $this->actingAs($officer)->deleteJson($this->url("/members/{$membership->id}"))->assertNoContent();

        $this->actingAs($parent)->getJson($this->url())->assertNotFound();
        $this->actingAs($parent)->getJson($this->url('/players'))->assertNotFound();
        $this->actingAs($parent)->getJson($this->url("/players/{$player->id}"))->assertNotFound();
        $this->assertSame(0, PlayerGuardian::count());
        $this->assertSame(0, TeamMemberRole::where('membership_id', $membership->id)->count());
        $this->assertNotNull(Player::find($player->id));

        $logs = $this->audits('member.removed');
        $this->assertCount(1, $logs);
        $this->assertSame($officer->id, $logs[0]->actor_user_id);
        $this->assertEquals(['user_id' => $parent->id, 'roles' => ['coach', 'parent'], 'guardians_unlinked' => 1], $logs[0]->details);
        $this->assertStringNotContainsString('山田', (string) json_encode(array_map(fn ($l) => $l->details, $logs), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, DB::table('outbox_messages')->where('kind', 'like', '%removed%')->count());
    }

    public function test_AC_004_08_the_representative_can_remove_an_officer(): void
    {
        $officer = $this->member([TeamRole::Officer]);

        $this->actingAs($this->representative)->deleteJson($this->url("/members/{$this->membershipOf($officer)->id}"))->assertNoContent();

        $this->actingAs($officer)->getJson($this->url())->assertNotFound();
    }
}
