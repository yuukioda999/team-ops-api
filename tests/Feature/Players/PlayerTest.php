<?php

namespace Tests\Feature\Players;

use App\Enums\Grade;
use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Player;
use App\Models\PlayerGuardian;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * 選手の登録・編集・削除(AC-003-01〜04・20)。
 */
class PlayerTest extends PlayerTestCase
{
    public function test_AC_003_01_a_representative_or_officer_registers_a_player(): void
    {
        $officer = $this->member([TeamRole::Officer]);

        foreach ([$this->representative, $officer] as $i => $actor) {
            $this->actingAs($actor)->postJson($this->url(), ['display_name' => "  選手{$i}　", 'grade' => 'e3', 'number' => 7])
                ->assertCreated()
                ->assertJsonPath('player.display_name', "選手{$i}")
                ->assertJsonPath('player.grade', 'e3')
                ->assertJsonPath('player.number', 7)
                ->assertJsonPath('player.guardians', [])
                ->assertJsonPath('possible_duplicate', false);
        }

        $this->assertSame(2, Player::where('team_id', $this->team->id)->count());
    }

    public function test_AC_003_01_the_number_is_optional_and_limited_to_0_to_999(): void
    {
        $this->actingAs($this->representative)->postJson($this->url(), ['display_name' => '番号なし', 'grade' => 'preschool'])
            ->assertCreated()->assertJsonPath('player.number', null);
        $this->actingAs($this->representative)->postJson($this->url(), ['display_name' => '番号0', 'grade' => 'j3', 'number' => 0])
            ->assertCreated()->assertJsonPath('player.number', 0);

        foreach ([['number' => 1000], ['number' => -1], ['number' => 'ten'], ['grade' => 'e7'], ['display_name' => ''], ['display_name' => str_repeat('あ', 31)], ['display_name' => "改行\nあり"]] as $invalid) {
            $this->actingAs($this->representative)->postJson($this->url(), [...['display_name' => '選手', 'grade' => 'e1', 'number' => 1], ...$invalid])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(array_keys($invalid));
        }
        $this->assertSame(2, Player::count());
    }

    public function test_AC_003_01_a_coach_or_parent_cannot_register_a_player(): void
    {
        foreach ([[TeamRole::Coach], [TeamRole::Parent]] as $roles) {
            $this->actingAs($this->member($roles))->postJson($this->url(), ['display_name' => '選手', 'grade' => 'e1'])->assertForbidden();
        }
        $this->assertSame(0, Player::count());
    }

    public function test_AC_003_02_editing_a_player_changes_only_the_sent_fields(): void
    {
        $player = $this->player('山田 一郎', Grade::E3, 10);

        $this->actingAs($this->representative)->patchJson($this->url("/{$player->id}"), ['grade' => 'e4'])
            ->assertOk()
            ->assertJsonPath('player.display_name', '山田 一郎')
            ->assertJsonPath('player.grade', 'e4')
            ->assertJsonPath('player.number', 10);

        $this->actingAs($this->representative)->patchJson($this->url("/{$player->id}"), ['number' => null])
            ->assertOk()->assertJsonPath('player.number', null);
    }

    public function test_AC_003_02_deleting_a_player_removes_the_row_and_the_guardian_links(): void
    {
        $parent = $this->member([TeamRole::Parent]);
        $player = $this->player();
        $this->link($player, $parent);

        $this->actingAs($this->representative)->deleteJson($this->url("/{$player->id}"))->assertNoContent();

        $this->assertNull(Player::find($player->id));
        $this->assertSame(0, PlayerGuardian::count());
        $this->assertSame(1, $this->team->members()->where('users.id', $parent->id)->count());
        $this->actingAs($this->representative)->getJson($this->url("/{$player->id}"))->assertNotFound();
    }

    public function test_AC_003_02_a_coach_or_parent_cannot_edit_or_delete(): void
    {
        $player = $this->player();
        foreach ([[TeamRole::Coach], [TeamRole::Parent]] as $roles) {
            $member = $this->member($roles);
            $this->link($player, $member);
            $this->actingAs($member)->patchJson($this->url("/{$player->id}"), ['grade' => 'e6'])->assertForbidden();
            $this->actingAs($member)->deleteJson($this->url("/{$player->id}"))->assertForbidden();
        }
        $this->assertSame(Grade::E3, $player->fresh()?->grade);
    }

    public function test_AC_003_03_a_same_name_in_the_same_grade_is_registered_with_a_warning(): void
    {
        $this->player('山田　一郎', Grade::E3);
        $this->player('佐藤 花子', Grade::E4);

        // 前後の空白・全角半角・大文字小文字をそろえて比べる
        foreach (['山田　一郎', ' 山田　一郎 ', 'ｙａｍａｄａ'] as $i => $name) {
            if ($i === 2) {
                $this->player('Yamada', Grade::E3);
            }
            $this->actingAs($this->representative)->postJson($this->url(), ['display_name' => $name, 'grade' => 'e3'])
                ->assertCreated()->assertJsonPath('possible_duplicate', true);
        }

        // 学年が違えば警告しない
        $this->actingAs($this->representative)->postJson($this->url(), ['display_name' => '山田　一郎', 'grade' => 'e5'])
            ->assertCreated()->assertJsonPath('possible_duplicate', false);
        $this->assertSame(7, Player::count()); // 用意した3人 + 登録した4人
    }

    public function test_AC_003_04_the_201st_player_is_rejected_with_the_reason(): void
    {
        $now = now();
        DB::table('players')->insert(array_map(fn (int $i) => [
            'team_id' => $this->team->id, 'display_name' => encrypt("選手{$i}", false), 'grade' => 'e1', 'number' => null, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, 199)));

        $this->actingAs($this->representative)->postJson($this->url(), ['display_name' => '200人目', 'grade' => 'e1'])->assertCreated();
        $this->actingAs($this->representative)->postJson($this->url(), ['display_name' => '201人目', 'grade' => 'e1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.players.0', '選手は1チーム200人までです。今は200人なので、登録できるのはあと0人です。');

        $this->assertSame(200, Player::where('team_id', $this->team->id)->count());
    }

    public function test_AC_003_04_the_limit_counts_only_this_team(): void
    {
        $other = Team::factory()->withRepresentative()->create();
        $now = now();
        DB::table('players')->insert(array_map(fn (int $i) => [
            'team_id' => $other->id, 'display_name' => encrypt("選手{$i}", false), 'grade' => 'e1', 'number' => null, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, 200)));

        $this->actingAs($this->representative)->postJson($this->url(), ['display_name' => '選手', 'grade' => 'e1'])->assertCreated();
    }

    public function test_AC_003_20_create_edit_and_delete_are_audited_without_values(): void
    {
        $id = (int) $this->actingAs($this->representative)->postJson($this->url(), ['display_name' => '山田 一郎', 'grade' => 'e3', 'number' => 10])->json('player.id');
        $this->actingAs($this->representative)->patchJson($this->url("/{$id}"), ['display_name' => '山田 一朗', 'number' => 11])->assertOk();
        $this->actingAs($this->representative)->patchJson($this->url("/{$id}"), ['number' => 11])->assertOk(); // 変化なしは記録しない
        $this->actingAs($this->representative)->deleteJson($this->url("/{$id}"))->assertNoContent();

        $logs = $this->audits('player.created', 'player.updated', 'player.deleted');
        $this->assertSame(['player.created', 'player.updated', 'player.deleted'], array_map(fn (AuditLog $l) => $l->action, $logs));
        $this->assertSame([$id, $id, $id], array_map(fn (AuditLog $l) => $l->subject_id, $logs));
        $this->assertSame($this->representative->id, $logs[0]->actor_user_id);
        $this->assertSame(['possible_duplicate' => false], $logs[0]->details);
        $this->assertSame(['fields' => ['display_name', 'number']], $logs[1]->details);
        $this->assertSame(['guardians_unlinked' => 0], $logs[2]->details);

        $raw = (string) DB::table('audit_logs')->pluck('details')->implode(' ');
        $this->assertStringNotContainsString('山田', $raw);
        $this->assertStringNotContainsString('一朗', json_encode(array_map(fn (AuditLog $l) => $l->details, $logs), JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_AC_003_20_the_display_name_is_stored_encrypted(): void
    {
        $player = $this->player('山田 一郎');

        $row = (array) DB::table('players')->where('id', $player->id)->first();
        $this->assertStringNotContainsString('山田', implode(' ', array_map('strval', $row)));
        $this->assertSame('山田 一郎', Player::findOrFail($player->id)->display_name);
        $this->assertStringNotContainsString('山田', $player->toJson(JSON_UNESCAPED_UNICODE));
    }
}
