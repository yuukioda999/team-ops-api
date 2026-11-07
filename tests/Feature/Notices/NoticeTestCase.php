<?php

namespace Tests\Feature\Notices;

use App\Enums\Grade;
use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\NotifyRelay\RelayClient;
use Database\Factories\TeamFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 005 のテストの共通部分。代表のいるチームと、役割ごとのメンバー、選手を用意する。
 * notify-relay は、テストごとに Http::fake で受け付けたことにする。
 */
abstract class NoticeTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $representative;

    protected Team $team;

    /** notify-relay が動いているか。false の間は接続できない */
    protected bool $relayUp = true;

    /**
     * notify-relay が受け付けた依頼の本文
     *
     * @var list<array<string, mixed>>
     */
    protected array $relayed = [];

    /**
     * notify-relay の状態の照会(AC-R001-21)への答え。null なら 404(7日を過ぎて消えた)
     *
     * @var array<string, mixed>|null
     */
    protected ?array $relayStatus = null;

    /** notify-relay が受け付けたときに返す delivery_id の通し番号 */
    private int $deliverySeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Http::fake は後から足しても先のものが優先されるので、1つの関数で動いている/止まっているを切り替える
        Http::fake(function (Request $request) {
            if (! $this->relayUp) {
                throw new ConnectionException('Connection refused');
            }
            if ($request->method() === 'GET') {
                return $this->relayStatus === null ? Http::response(['title' => 'Delivery not found'], 404) : Http::response($this->relayStatus);
            }
            $body = json_decode($request->body(), true);
            $this->relayed[] = is_array($body) ? $body : [];
            $this->deliverySeq++;

            return Http::response(['delivery_id' => self::deliveryId($this->deliverySeq)], 202);
        });
        $this->representative = User::factory()->create(['email' => 'rep@example.invalid', 'display_name' => '代表 太郎']);
        $this->team = Team::factory()->withRepresentative()->create(['name' => 'つくばFC', 'created_by' => $this->representative->id]);
    }

    /**
     * テスト用の delivery_id(notify-relay の形式)
     */
    protected static function deliveryId(int $n): string
    {
        return 'dlv_'.str_pad((string) $n, 26, '0', STR_PAD_LEFT);
    }

    /**
     * notify-relay の webhook を、署名を付けて送る(ADR-0019)。
     *
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    protected function relayEvent(array $event, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $path = '/api/internal/notify-relay/events';
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $secret ??= (string) base64_decode('dGVzdC1yZWxheS1zaWduaW5nLWtleS0zMi1ieXRlcyE=', true);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RELAY_KEY_ID' => 'k-test',
            'HTTP_X_RELAY_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_RELAY_SIGNATURE' => RelayClient::sign($secret, $timestamp, 'POST', $path, $body),
        ], $body);
    }

    /**
     * 送ったお知らせのメールの結果を webhook で返す。
     *
     * @param  array<int, array{0: string, 1?: string|null}>  $results  ユーザーID => [状態, error_code]
     * @return TestResponse<Response>
     */
    protected function results(string $dedupeKey, array $results, ?string $occurredAt = null, ?string $eventId = null): TestResponse
    {
        static $seq = 0;
        $seq++;

        return $this->relayEvent([
            'event_id' => $eventId ?? 'evt_'.str_pad((string) $seq, 26, '0', STR_PAD_LEFT),
            'type' => 'delivery.results',
            'delivery_id' => self::deliveryId(1),
            'dedupe_key' => $dedupeKey,
            'results' => array_map(fn (int $userId, array $r) => [
                'recipient_ref' => "user_{$userId}",
                'status' => $r[0],
                'error_code' => $r[1] ?? null,
                'attempts' => 1,
                'occurred_at' => $occurredAt ?? now()->toIso8601String(),
            ], array_keys($results), array_values($results)),
            'sent_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  list<TeamRole>  $roles
     */
    protected function member(array $roles, string $name, ?Team $team = null): User
    {
        $user = User::factory()->create(['display_name' => $name, 'email' => strtolower(bin2hex(random_bytes(4))).'@example.invalid']);
        TeamFactory::addMember($team ?? $this->team, $user, $roles);

        return $user;
    }

    /**
     * 選手を作り、保護者を紐づける。
     */
    protected function child(Grade $grade, User ...$guardians): Player
    {
        $player = Player::create(['team_id' => $this->team->id, 'display_name' => '選手', 'grade' => $grade, 'number' => null]);
        foreach ($guardians as $guardian) {
            $player->guardians()->create(['membership_id' => TeamMembership::where('team_id', $this->team->id)->where('user_id', $guardian->id)->sole()->id]);
        }

        return $player;
    }

    protected function runCommand(string $command): void
    {
        $this->assertSame(0, Artisan::call($command));
    }

    protected function url(string $path = ''): string
    {
        return "/api/teams/{$this->team->id}/notices{$path}";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function input(array $overrides = []): array
    {
        return ['title' => '来週の試合', 'body' => "集合は8時です。\n詳しくは https://example.invalid/match を見てください。", 'target' => ['type' => 'all', 'values' => []], ...$overrides];
    }

    /**
     * notify-relay が受け付けたお知らせの依頼
     *
     * @return list<array<string, mixed>>
     */
    protected function noticeRequests(): array
    {
        return array_values(array_filter($this->relayed, fn (array $r) => str_contains((string) ($r['dedupe_key'] ?? ''), ':notice:')));
    }

    /**
     * notify-relay が受け付けたお知らせのメールの宛先(メールアドレス)を、依頼ごとに返す。
     *
     * @return list<list<string>>
     */
    protected function deliveredTo(): array
    {
        return array_map(function (array $request): array {
            $recipients = is_array($request['recipients'] ?? null) ? $request['recipients'] : [];

            return array_values(array_map(fn ($r) => is_array($r) ? (string) ($r['email'] ?? '') : '', $recipients));
        }, $this->noticeRequests());
    }

    /**
     * @return list<AuditLog>
     */
    protected function audits(string $action): array
    {
        return array_values(AuditLog::where('team_id', $this->team->id)->where('action', $action)->orderBy('id')->get()->all());
    }
}
