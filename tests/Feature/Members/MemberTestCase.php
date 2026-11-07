<?php

namespace Tests\Feature\Members;

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 004 のテストの共通部分。代表のいるチームと、役割ごとのメンバーを用意する。
 */
abstract class MemberTestCase extends TestCase
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
    protected function member(array $roles, string $displayName = 'メンバー', ?string $email = null): User
    {
        $user = User::factory()->create(['display_name' => $displayName, ...($email !== null ? ['email' => $email] : [])]);
        TeamFactory::addMember($this->team, $user, $roles);

        return $user;
    }

    protected function membershipOf(User $user): TeamMembership
    {
        return TeamMembership::where('team_id', $this->team->id)->where('user_id', $user->id)->sole();
    }

    /**
     * @return list<string>
     */
    protected function rolesOf(User $user): array
    {
        return array_map(fn (TeamRole $r) => $r->value, $this->membershipOf($user)->roleList());
    }

    protected function url(string $path = ''): string
    {
        return "/api/teams/{$this->team->id}{$path}";
    }

    /**
     * @return list<AuditLog>
     */
    protected function audits(string $action): array
    {
        return array_values(AuditLog::where('team_id', $this->team->id)->where('action', $action)->orderBy('id')->get()->all());
    }

    /**
     * @return list<array{to: string, subject: string, text: string}>
     */
    protected function mails(string $kind): array
    {
        return array_values(OutboxMessage::where('kind', $kind)->orderBy('id')->get()->map(function (OutboxMessage $m): array {
            $payload = $m->payload;
            $recipients = is_array($payload['recipients'] ?? null) ? $payload['recipients'] : [];
            $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];

            return [
                'to' => (string) (is_array($recipients[0] ?? null) ? ($recipients[0]['email'] ?? '') : ''),
                'subject' => (string) ($message['subject'] ?? ''),
                'text' => (string) ($message['text'] ?? ''),
            ];
        })->all());
    }
}
