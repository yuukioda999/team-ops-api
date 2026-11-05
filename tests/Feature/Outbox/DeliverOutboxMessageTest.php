<?php

namespace Tests\Feature\Outbox;

use App\Enums\OutboxStatus;
use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use App\Support\NotifyRelay\RelayClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * アウトボックスから notify-relay への送信(ADR-0002)。
 */
class DeliverOutboxMessageTest extends TestCase
{
    use RefreshDatabase;

    private const string RELAY_URL = 'http://notify-relay.test/v1/deliveries';

    private function pendingMessage(): OutboxMessage
    {
        return OutboxMessage::create([
            'kind' => 'login_link',
            'dedupe_key' => 'login-link:1',
            'payload' => [
                'dedupe_key' => 'login-link:1',
                'tenant_ref' => 'system',
                'message' => ['subject' => 'ログイン用のリンク', 'text' => '本文'],
                'recipients' => [['recipient_ref' => 'user_1', 'email' => 'coach@example.invalid']],
            ],
        ]);
    }

    private function runJob(OutboxMessage $message): DeliverOutboxMessage
    {
        $job = new DeliverOutboxMessage($message->id);
        $job->setJob($this->createMock(Job::class));
        $job->handle(app(RelayClient::class));

        return $job;
    }

    public function test_the_request_is_signed_so_notify_relay_can_verify_it(): void
    {
        Http::fake([self::RELAY_URL => Http::response(['delivery_id' => 'dlv_X'], 202)]);

        $this->runJob($this->pendingMessage());

        Http::assertSent(function (Request $request): bool {
            $secret = (string) base64_decode((string) config('team_ops.notify_relay.secret'), true);
            $ts = (int) $request->header('X-Relay-Timestamp')[0];
            $expected = 'v1='.hash_hmac('sha256', $ts.'.'.$request->body(), $secret);

            return $request->url() === self::RELAY_URL
                && $request->header('X-Relay-Key-Id')[0] === 'k-test'
                && hash_equals($expected, $request->header('X-Relay-Signature')[0])
                && abs(time() - $ts) < 60
                && $request['dedupe_key'] === 'login-link:1';
        });
    }

    /**
     * @return array<string, array{int, OutboxStatus, string|null}>
     */
    public static function relayResponses(): array
    {
        return [
            'accepted (202)' => [202, OutboxStatus::Sent, null],
            'AC-R001-03 already accepted (200) is not sent again' => [200, OutboxStatus::Sent, null],
            'temporarily unavailable (503) is retried' => [503, OutboxStatus::Pending, 'http_503'],
            'too many requests (429) is retried' => [429, OutboxStatus::Pending, 'http_429'],
            'server error (500) is retried' => [500, OutboxStatus::Pending, 'http_500'],
            'wrong signing key (401) stops' => [401, OutboxStatus::Failed, 'unauthorized'],
            'AC-R001-04 conflicting dedupe key (409) stops' => [409, OutboxStatus::Failed, 'http_409'],
            'invalid request (422) stops' => [422, OutboxStatus::Failed, 'http_422'],
        ];
    }

    #[DataProvider('relayResponses')]
    public function test_the_outbox_status_follows_the_relay_response(int $status, OutboxStatus $expected, ?string $error): void
    {
        Http::fake([self::RELAY_URL => Http::response(['delivery_id' => 'dlv_X'], $status)]);
        $message = $this->pendingMessage();

        $this->runJob($message);

        $message->refresh();
        $this->assertSame($expected, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertSame($error, $message->last_error);
        $this->assertSame($expected === OutboxStatus::Pending, $message->next_attempt_at !== null);
    }

    public function test_a_connection_failure_is_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));
        $message = $this->pendingMessage();

        $this->runJob($message);

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertSame('connection_failed', $message->last_error);
    }

    public function test_a_message_that_was_already_sent_is_not_sent_again(): void
    {
        Http::fake();
        $message = $this->pendingMessage();
        $message->forceFill(['status' => OutboxStatus::Sent])->save();

        $this->runJob($message);

        Http::assertNothingSent();
    }

    public function test_giving_up_marks_the_message_failed(): void
    {
        $message = $this->pendingMessage();

        (new DeliverOutboxMessage($message->id))->failed(new RuntimeException('timed out'));

        $message->refresh();
        $this->assertSame(OutboxStatus::Failed, $message->status);
        $this->assertSame('gave_up', $message->last_error);
    }

    public function test_AC_000_04_logs_about_the_delivery_do_not_contain_the_recipient(): void
    {
        Http::fake([self::RELAY_URL => Http::response([], 422)]);
        $log = Log::spy();

        $this->runJob($this->pendingMessage());

        $log->shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            $text = $message.json_encode($context);

            return ! str_contains($text, 'coach@example.invalid') && ! str_contains($text, 'ログイン用のリンク');
        });
    }

    public function test_the_job_carries_only_the_id(): void
    {
        $message = $this->pendingMessage();

        $serialized = serialize(new DeliverOutboxMessage($message->id));

        $this->assertStringNotContainsString('coach@example.invalid', $serialized);
        $this->assertStringNotContainsString('login-link', $serialized);
    }
}
