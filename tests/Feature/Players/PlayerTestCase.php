<?php

namespace Tests\Feature\Players;

use App\Enums\Grade;
use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 003 のテストの共通部分。代表のいるチームと、役割ごとのメンバーを用意する。
 */
abstract class PlayerTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $representative;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->representative = User::factory()->create(['email' => 'rep@example.invalid', 'display_name' => '代表 太郎']);
        $this->team = Team::factory()->withRepresentative()->create(['name' => 'つくばFC U-10', 'created_by' => $this->representative->id]);
    }

    /**
     * @param  list<TeamRole>  $roles
     */
    protected function member(array $roles, string $displayName = 'メンバー'): User
    {
        $user = User::factory()->create(['display_name' => $displayName]);
        TeamFactory::addMember($this->team, $user, $roles);

        return $user;
    }

    protected function membershipOf(User $user, ?Team $team = null): TeamMembership
    {
        return TeamMembership::where('team_id', ($team ?? $this->team)->id)->where('user_id', $user->id)->sole();
    }

    protected function player(string $displayName = '山田 一郎', Grade $grade = Grade::E3, ?int $number = 10, ?Team $team = null): Player
    {
        return Player::create(['team_id' => ($team ?? $this->team)->id, 'display_name' => $displayName, 'grade' => $grade, 'number' => $number]);
    }

    protected function link(Player $player, User $guardian): void
    {
        $player->guardians()->create(['membership_id' => $this->membershipOf($guardian)->id]);
    }

    protected function url(string $path = ''): string
    {
        return "/api/teams/{$this->team->id}/players{$path}";
    }

    /**
     * @return list<AuditLog>
     */
    protected function audits(string ...$actions): array
    {
        return array_values(AuditLog::where('team_id', $this->team->id)->whereIn('action', $actions)->orderBy('id')->get()->all());
    }

    protected function latestAudit(string $action): ?AuditLog
    {
        return AuditLog::where('action', $action)->latest('id')->first();
    }
}
