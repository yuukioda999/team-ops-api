<?php

namespace Tests\Feature\Outbox;

use App\Enums\OutboxStatus;
use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use App\Support\NotifyRelay\EmailOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-0002:配信依頼はアウトボックスに記録し、コミット後に送る。
 */
class EmailOutboxTest extends TestCase
{
    use RefreshDatabase;

    private function outbox(bool $invalidOnly = false): EmailOutbox
    {
        return new EmailOutbox($invalidOnly);
    }

    private function queueLoginLink(EmailOutbox $outbox, string $key = 'login-link:1', string $email = 'coach@example.invalid'): OutboxMessage
    {
        return $outbox->queue(
            kind: 'login_link',
            dedupeKey: $key,
            subject: 'ログイン用のリンク',
            text: '下のリンクからログインしてください。',
            recipients: ['user_1' => $email],
            url: 'http://localhost:5173/login/verify?token=abc',
            expiresAt: now()->addMinutes(15),
        );
    }

    public function test_ADR_0002_a_queued_email_is_stored_as_pending_with_the_relay_request(): void
    {
        Queue::fake();

        $message = $this->queueLoginLink($this->outbox());

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $payload = $message->payload;
        $this->assertIsArray($payload);
        $this->assertSame('login-link:1', $payload['dedupe_key']);
        $this->assertSame('system', $payload['tenant_ref']);
        $this->assertSame([['recipient_ref' => 'user_1', 'email' => 'coach@example.invalid']], $payload['recipients']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $payload['expires_at'], 'RFC 3339 date-time');
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, $message->give_up_at?->timestamp, 2, 'A mail with an expiry is retried only until then (D3)');
        Queue::assertPushed(DeliverOutboxMessage::class, fn ($job) => $job->outboxMessageId === $message->id);
    }

    public function test_optional_fields_are_left_out_and_the_deadline_defaults_to_24_hours(): void
    {
        Queue::fake();

        $message = $this->outbox()->queue('notice', 'notice-key-0001', '件名', '本文', ['user_1' => 'a@example.invalid']);

        $payload = $message->refresh()->payload ?? [];
        $this->assertArrayNotHasKey('expires_at', $payload);
        $this->assertArrayNotHasKey('url', $payload['message']);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $message->give_up_at?->timestamp, 2);
    }

    public function test_AC_000_04_the_payload_is_encrypted_at_rest(): void
    {
        Queue::fake();

        $this->queueLoginLink($this->outbox());

        $raw = (string) DB::table('outbox_messages')->value('payload');
        $this->assertStringNotContainsString('coach@example.invalid', $raw);
        $this->assertStringNotContainsString('token=abc', $raw);
    }

    public function test_the_job_is_queued_only_after_the_surrounding_transaction_commits(): void
    {
        config(['queue.default' => 'database']);

        try {
            DB::transaction(function (): void {
                $this->queueLoginLink($this->outbox(), 'login-link:rolled-back');
                throw new RuntimeException('business save failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, OutboxMessage::count());
        $this->assertSame(0, DB::table('jobs')->count(), 'No job may be queued for a rolled-back message');

        DB::transaction(fn () => $this->queueLoginLink($this->outbox(), 'login-link:committed'));
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_ADR_0002_queuing_the_same_dedupe_key_twice_keeps_one_message(): void
    {
        Queue::fake();

        $first = $this->queueLoginLink($this->outbox());
        $second = $this->queueLoginLink($this->outbox());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, OutboxMessage::count());
        Queue::assertPushed(DeliverOutboxMessage::class, 1);
    }

    public function test_the_same_dedupe_key_with_different_content_is_refused(): void
    {
        Queue::fake();
        $this->queueLoginLink($this->outbox());

        $this->expectException(LogicException::class);
        $this->queueLoginLink($this->outbox(), email: 'other@example.invalid');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidRequests(): array
    {
        return [
            'dedupe key too short' => [['dedupeKey' => 'short']],
            'dedupe key with spaces' => [['dedupeKey' => 'has spaces in it']],
            'empty subject' => [['subject' => '']],
            'subject with a line break' => [['subject' => "a\r\nBcc: x@example.invalid"]],
            'subject over 200 characters' => [['subject' => str_repeat('あ', 201)]],
            'empty text' => [['text' => '']],
            'text over 2000 characters' => [['text' => str_repeat('あ', 2001)]],
            'relative url' => [['url' => '/login']],
            'no recipients' => [['recipients' => []]],
            'more than 1000 recipients' => [['recipients' => array_fill_keys(array_map(fn ($i) => "r{$i}", range(1, 1001)), 'a@example.invalid')]],
            'invalid address' => [['recipients' => ['user_1' => 'not-an-address']]],
            'recipient ref over 64 characters' => [['recipients' => [str_repeat('r', 65) => 'a@example.invalid']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidRequests')]
    public function test_a_request_that_breaks_the_relay_contract_is_refused_before_commit(array $override): void
    {
        Queue::fake();
        $args = $override + [
            'kind' => 'login_link',
            'dedupeKey' => 'login-link:1',
            'subject' => '件名',
            'text' => '本文',
            'recipients' => ['user_1' => 'coach@example.invalid'],
            'url' => null,
        ];

        try {
            $this->outbox()->queue(...$args);
            $this->fail('An invalid request was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('coach@example.invalid', $e->getMessage());
        }
        $this->assertSame(0, OutboxMessage::count());
        Queue::assertNothingPushed();
    }

    public function test_invalid_recipients_only_refuses_real_addresses_without_naming_them(): void
    {
        Queue::fake();

        try {
            $this->queueLoginLink($this->outbox(invalidOnly: true), email: 'parent@example.com');
            $this->fail('A real address was accepted with invalid recipients only');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('parent@example.com', $e->getMessage());
        }
        $this->assertSame(0, OutboxMessage::count());
    }

    public function test_invalid_recipients_only_accepts_invalid_domain_addresses(): void
    {
        Queue::fake();

        $this->queueLoginLink($this->outbox(invalidOnly: true), email: 'coach@EXAMPLE.invalid');

        $this->assertSame(1, OutboxMessage::count());
    }
}
