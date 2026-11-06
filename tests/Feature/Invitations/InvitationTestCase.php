<?php

namespace Tests\Feature\Invitations;

use App\Enums\TeamRole;
use App\Models\OutboxMessage;
use App\Models\Team;
use App\Models\User;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 002 のテストの共通部分。代表のいるチームと、役割ごとのメンバーを用意する。
 */
abstract class InvitationTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $representative;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // notify-relay への送信は動かさず、アウトボックスの中身を確かめる

        $this->representative = User::factory()->create(['email' => 'rep@example.invalid', 'display_name' => '代表 太郎']);
        $this->team = Team::factory()->withRepresentative()->create(['name' => 'つくばFC U-10', 'created_by' => $this->representative->id]);
    }

    /**
     * @param  list<TeamRole>  $roles
     */
    protected function member(array $roles, string $email = 'member@example.invalid'): User
    {
        $user = User::factory()->create(['email' => $email, 'display_name' => 'メンバー']);
        TeamFactory::addMember($this->team, $user, $roles);

        return $user;
    }

    /**
     * 招待リンクを発行し、その URL のトークンを返す。
     */
    protected function issueLink(?User $by = null): string
    {
        return $this->tokenOf($this->actingAs($by ?? $this->representative)
            ->postJson("/api/teams/{$this->team->id}/invitation")
            ->assertCreated()
            ->json('invitation.url'));
    }

    protected function applicant(string $email = 'parent@example.invalid'): User
    {
        return User::factory()->create(['email' => $email, 'display_name' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function application(string $token, string $displayName = '山田 花子', ?string $message = '3年 山田太郎の母です'): array
    {
        return ['token' => $token, 'display_name' => $displayName, 'message' => $message];
    }

    protected function latestMail(string $kind): ?OutboxMessage
    {
        return OutboxMessage::where('kind', $kind)->latest('id')->first();
    }

    /**
     * @return list<string>
     */
    protected function recipientsOf(?OutboxMessage $mail): array
    {
        $recipients = $mail?->payload['recipients'] ?? null;
        $this->assertIsArray($recipients);

        return array_column($recipients, 'email');
    }

    /**
     * @return array<string, string>
     */
    protected function messageOf(?OutboxMessage $mail): array
    {
        $message = $mail?->payload['message'] ?? null;
        $this->assertIsArray($message);

        return $message;
    }

    protected function tokenOf(mixed $url): string
    {
        $this->assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertIsString($query['token'] ?? null);

        return $query['token'];
    }
}
