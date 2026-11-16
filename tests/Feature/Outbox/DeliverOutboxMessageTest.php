<?php

namespace Tests\Feature\Outbox;

use App\Enums\OutboxStatus;
use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use App\Support\NotifyRelay\RelayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-0002:アウトボックスから notify-relay への送信。
 */
class DeliverOutboxMessageTest extends TestCase
{
    use RefreshDatabase;

    private const string RELAY_URL = 'http://notify-relay.test/v1/deliveries';

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pendingMessage(array $attributes = []): OutboxMessage
    {
        return OutboxMessage::create($attributes + [
            'kind' => 'login_link',
            'dedupe_key' => 'login-link:1',
            'give_up_at' => now()->addMinutes(15),
            'payload' => [
                'dedupe_key' => 'login-link:1',
                'tenant_ref' => 'system',
                'message' => ['subject' => 'ログイン用のリンク', 'text' => '本文', 'url' => 'http://localhost:5173/login/verify?token=secret-token'],
                'recipients' => [['recipient_ref' => 'user_1', 'email' => 'coach@example.invalid']],
            ],
        ]);
    }

    private function runJob(OutboxMessage $message): void
    {
        (new DeliverOutboxMessage($message->id))->handle(app(RelayClient::class));
    }

    public function test_ADR_0002_the_request_is_signed_with_method_and_path(): void
    {
        Http::fake([self::RELAY_URL => Http::response(['delivery_id' => 'dlv_X'], 202)]);

        $this->runJob($this->pendingMessage());

        Http::assertSent(function (Request $request): bool {
            $secret = (string) base64_decode((string) config('team_ops.notify_relay.secret'), true);
            $ts = (int) $request->header('X-Relay-Timestamp')[0];
            $expected = 'v1='.hash_hmac('sha256', $ts.'.POST./v1/deliveries.'.$request->body(), $secret);

            return $request->url() === self::RELAY_URL
                && $request->header('X-Relay-Key-Id')[0] === 'k-test'
                && hash_equals($expected, $request->header('X-Relay-Signature')[0])
                && abs(time() - $ts) < 60;
        });
    }

    public function test_the_signature_matches_the_vector_shared_with_notify_relay(): void
    {
        // notify-relay の TestSignKnownVector と同じ値。形式がずれると両方のテストが落ちる
        $this->assertSame(
            'v1=aeefeda4cddc7dc373c15cefb78705f1cae71280291b4b3322fbe292f1bea113',
            RelayClient::sign('0123456789abcdef0123456789abcdef', 1793502000, 'POST', '/v1/deliveries', '{"dedupe_key":"login-link:1"}'),
        );
    }

    /**
     * @return array<string, array{int, OutboxStatus, string|null}>
     */
    public static function relayResponses(): array
    {
        return [
            'accepted (202)' => [202, OutboxStatus::Sent, null],
            'already accepted (200) is not sent again' => [200, OutboxStatus::Sent, null],
            'temporarily unavailable (503) is retried' => [503, OutboxStatus::Pending, 'http_503'],
            'bad gateway (502) is retried' => [502, OutboxStatus::Pending, 'http_502'],
            'too many requests (429) is retried' => [429, OutboxStatus::Pending, 'http_429'],
            'wrong relay URL (404) is retried after the setting is fixed' => [404, OutboxStatus::Pending, 'http_404'],
            'wrong signing key (401) stops' => [401, OutboxStatus::Failed, 'unauthorized'],
            'conflicting dedupe key (409) stops' => [409, OutboxStatus::Failed, 'http_409'],
            'invalid request (422) stops' => [422, OutboxStatus::Failed, 'http_422'],
            'a redirect (307) is not followed and stops' => [307, OutboxStatus::Failed, 'http_307'],
        ];
    }

    #[DataProvider('relayResponses')]
    public function test_ADR_0002_the_outbox_status_follows_the_relay_response(int $status, OutboxStatus $expected, ?string $error): void
    {
        Http::fake([self::RELAY_URL => Http::response(['delivery_id' => 'dlv_X'], $status, $status === 307 ? ['Location' => 'http://elsewhere.test/'] : [])]);
        $message = $this->pendingMessage();

        $this->runJob($message);

        $message->refresh();
        $this->assertSame($expected, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertSame($error, $message->last_error);
        $this->assertSame($expected === OutboxStatus::Pending, $message->next_attempt_at !== null);
        Http::assertSentCount(1);
    }

    public function test_a_connection_failure_or_timeout_is_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('Operation timed out'));
        $message = $this->pendingMessage();

        $this->runJob($message);

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertSame('connection_failed', $message->last_error);
    }

    public function test_ADR_0003_the_recipients_and_body_are_deleted_once_the_message_is_final(): void
    {
        Http::fake([self::RELAY_URL => Http::response(['delivery_id' => 'dlv_X'], 202)]);
        $message = $this->pendingMessage();

        $this->runJob($message);

        $message->refresh();
        $this->assertSame(OutboxStatus::Sent, $message->status);
        $this->assertNull($message->payload);
    }

    public function test_the_retry_waits_longer_after_each_failure(): void
    {
        $this->travelTo(now()->startOfMinute());
        Http::fake([self::RELAY_URL => Http::response([], 503)]);
        $message = $this->pendingMessage(['give_up_at' => now()->addDays(2)]);

        $waits = [];
        foreach (range(1, 7) as $attempt) {
            $this->runJob($message);
            $message->refresh();
            $this->assertNotNull($message->next_attempt_at);
            $waits[] = (int) now()->diffInSeconds($message->next_attempt_at);
            $this->travelTo($message->next_attempt_at);
        }

        $this->assertSame([30, 60, 120, 300, 600, 1800, 1800], $waits);
    }

    public function test_a_message_that_is_not_due_yet_is_not_sent(): void
    {
        Http::fake();
        $message = $this->pendingMessage(['next_attempt_at' => now()->addMinute()]);

        $this->runJob($message);

        Http::assertNothingSent();
    }

    public function test_two_jobs_for_the_same_message_send_only_once(): void
    {
        Http::fake([self::RELAY_URL => Http::response([], 503)]);
        $message = $this->pendingMessage();

        $this->runJob($message);
        $this->runJob($message); // a duplicate job right after: the message is reserved until next_attempt_at

        Http::assertSentCount(1);
        $this->assertSame(1, $message->refresh()->attempts);
    }

    public function test_a_message_that_was_already_sent_is_not_sent_again(): void
    {
        Http::fake();
        $message = $this->pendingMessage();
        $message->forceFill(['status' => OutboxStatus::Sent])->save();

        $this->runJob($message);

        Http::assertNothingSent();
    }

    public function test_a_message_past_its_deadline_is_given_up_without_sending(): void
    {
        Http::fake();
        $message = $this->pendingMessage(['give_up_at' => now()->subSecond()]);

        $this->runJob($message);

        $message->refresh();
        $this->assertSame(OutboxStatus::Failed, $message->status);
        $this->assertSame('gave_up', $message->last_error);
        $this->assertNull($message->payload);
        Http::assertNothingSent();
    }

    public function test_an_unexpected_exception_does_not_cause_an_immediate_retry(): void
    {
        Http::fake(fn () => throw new RuntimeException('unexpected'));
        $message = $this->pendingMessage();

        try {
            $this->runJob($message);
            $this->fail('The exception was swallowed');
        } catch (RuntimeException) {
        }

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertTrue($message->next_attempt_at?->isFuture(), 'The next try waits for the backoff');
        $this->assertSame(1, (new DeliverOutboxMessage($message->id))->tries, 'The queue does not retry by itself');
    }

    public function test_AC_000_04_logs_about_the_delivery_do_not_contain_the_recipient_or_the_link(): void
    {
        Http::fake([self::RELAY_URL => Http::response([], 422)]);
        $log = Log::spy();

        $this->runJob($this->pendingMessage());

        $log->shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            $text = $message.json_encode($context);

            return ! str_contains($text, 'coach@example.invalid') && ! str_contains($text, 'secret-token');
        });
    }

    public function test_AC_000_04_the_job_carries_only_the_id(): void
    {
        $message = $this->pendingMessage();

        $serialized = serialize(new DeliverOutboxMessage($message->id));

        $this->assertStringNotContainsString('coach@example.invalid', $serialized);
        $this->assertStringNotContainsString('secret-token', $serialized);
    }

    /**
     * outbox:redispatch --now が、送っている途中の件と重なったとき(AC-009-12、ADR-0018 の 2026-11-16 追記)。
     * 先に送っていたジョブA の結果が、あとから予約し直したジョブB の結果を上書きしない。
     */
    public function test_AC_009_12_a_job_whose_reservation_was_taken_over_does_not_overwrite_the_final_state(): void
    {
        $message = $this->pendingMessage();
        $calls = 0;
        Http::fake([self::RELAY_URL => function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                // ジョブA が notify-relay の応答を待っている間に、運用する人が --now を打つ。
                // 積まれたジョブB はその場で動き(キューは sync)、受け付けられる
                $this->assertSame(0, Artisan::call('outbox:redispatch', ['--now' => true]));

                return Http::response(['error' => 'unavailable'], 503); // A には一時的な失敗が返る
            }

            return Http::response(['delivery_id' => 'dlv_B'], 202);
        }]);

        $this->runJob($message);

        $this->assertSame(2, $calls);
        $message->refresh();
        $this->assertSame(OutboxStatus::Sent, $message->status);
        $this->assertSame(2, $message->attempts);
        $this->assertNull($message->last_error);
        $this->assertSame('dlv_B', $message->delivery_id);
        $this->assertNull($message->payload);
    }

    public function test_AC_009_12_a_job_whose_reservation_was_taken_over_does_not_give_up_a_message_already_sent(): void
    {
        $message = $this->pendingMessage();
        $calls = 0;
        Http::fake([self::RELAY_URL => function () use (&$calls, $message) {
            $calls++;
            if ($calls === 1) {
                $this->assertSame(0, Artisan::call('outbox:redispatch', ['--now' => true]));
                // B が受け付けられたあと、A には「受け付けない」(再送しない失敗)が返る
                $this->assertSame(OutboxStatus::Sent, $message->refresh()->status);

                return Http::response(['error' => 'invalid_request'], 422);
            }

            return Http::response(['delivery_id' => 'dlv_B'], 202);
        }]);

        $this->runJob($message);

        $this->assertSame(OutboxStatus::Sent, $message->refresh()->status);
        $this->assertNull($message->last_error);
    }

    /**
     * @return array<string, array{int, array<string, mixed>}>
     */
    public static function answersWhileAnotherJobHoldsTheReservation(): array
    {
        return [
            'accepted' => [202, ['delivery_id' => 'dlv_A']],
            'rejected' => [422, ['error' => 'invalid_request']],
            'unavailable' => [503, ['error' => 'unavailable']],
        ];
    }

    /**
     * B が予約し直したが、まだ送っている途中に A に答えが返る(AC-009-12)。A は何も書かず、B に任せる。
     *
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('answersWhileAnotherJobHoldsTheReservation')]
    public function test_AC_009_12_a_job_that_lost_its_reservation_writes_nothing_while_the_other_job_is_still_sending(int $status, array $body): void
    {
        $message = $this->pendingMessage();
        Http::fake([self::RELAY_URL => function () use ($message, $status, $body) {
            // --now のあと、B が予約した(attempts が1つ増え、送信待ちのまま)
            OutboxMessage::whereKey($message->id)->update(['attempts' => 2]);

            return Http::response($body, $status);
        }]);

        $this->runJob($message);

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertSame(2, $message->attempts);
        $this->assertNull($message->delivery_id);
        $this->assertNull($message->last_error);
        $this->assertNotNull($message->payload);
    }

    public function test_AC_009_12_a_job_that_lost_its_reservation_does_not_give_up(): void
    {
        $message = $this->pendingMessage(['give_up_at' => now()->subMinute()]);
        // A が予約し、期限切れに気づいて「諦めた」と書く前に、B が予約し直した(--now と重なった)
        $job = new DeliverOutboxMessage($message->id);
        $reserved = (new \ReflectionMethod($job, 'reserve'))->invoke($job);
        $this->assertInstanceOf(OutboxMessage::class, $reserved);
        OutboxMessage::whereKey($message->id)->update(['attempts' => 2]);
        $finish = new \ReflectionMethod($job, 'finish');

        $this->assertFalse($finish->invoke($job, $reserved, OutboxStatus::Failed, 'gave_up'));

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertNotNull($message->payload);
    }
}
