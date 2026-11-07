<?php

namespace Tests\Feature\Members;

use App\Enums\TeamRole;

/**
 * チーム名・競技の変更(AC-004-11)。
 */
class TeamSettingsTest extends MemberTestCase
{
    public function test_AC_004_11_the_representative_renames_the_team_and_members_see_the_new_name(): void
    {
        $parent = $this->member([TeamRole::Parent]);

        $this->actingAs($this->representative)->patchJson($this->url(), ['name' => '  つくばFC U-12　', 'sport' => 'baseball'])
            ->assertOk()
            ->assertJsonPath('name', 'つくばFC U-12')
            ->assertJsonPath('sport', 'baseball')
            ->assertJsonPath('roles', ['representative']);

        $this->actingAs($parent)->getJson($this->url())->assertJsonPath('name', 'つくばFC U-12');
        $this->actingAs($parent)->getJson('/api/me')->assertJsonPath('teams.0.name', 'つくばFC U-12');

        $logs = $this->audits('team.updated');
        $this->assertCount(1, $logs);
        $this->assertEquals(['fields' => ['name', 'sport']], $logs[0]->details);
    }

    public function test_AC_004_11_only_the_sent_field_changes_and_no_change_is_not_audited(): void
    {
        $this->actingAs($this->representative)->patchJson($this->url(), ['sport' => 'other'])->assertOk()->assertJsonPath('name', 'つくばFC U-10');
        $this->actingAs($this->representative)->patchJson($this->url(), ['sport' => 'other'])->assertOk();

        $this->assertCount(1, $this->audits('team.updated'));
    }

    public function test_AC_004_11_the_same_checks_as_creating_a_team(): void
    {
        foreach ([['name' => ''], ['name' => str_repeat('あ', 51)], ['name' => "改行\nあり"], ['sport' => 'tennis']] as $invalid) {
            $this->actingAs($this->representative)->patchJson($this->url(), $invalid)->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        }
        $this->actingAs($this->representative)->patchJson($this->url(), ['name' => str_repeat('あ', 50)])->assertOk();
    }

    public function test_AC_004_11_others_cannot_change_the_team_even_with_invalid_input(): void
    {
        $officer = $this->member([TeamRole::Officer]);

        $this->actingAs($officer)->patchJson($this->url(), ['name' => ''])->assertForbidden();
        $this->actingAs($officer)->getJson($this->url())->assertJsonPath('name', 'つくばFC U-10');
    }
}
