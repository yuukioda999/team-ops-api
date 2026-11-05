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
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-0002:配信依頼はアウトボックスに記録し、コミット後に送る。
 */
class EmailOutboxTest extends TestCase
{
    use RefreshDatabase;

    private function outbox(bool $demo = false): EmailOutbox
    {
        return new EmailOutbox($demo);
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

    public function test_a_queued_email_is_stored_as_pending_with_the_relay_request(): void
    {
        Queue::fake();

        $message = $this->queueLoginLink($this->outbox());

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $payload = $message->payload;
        $this->assertSame('login-link:1', $payload['dedupe_key']);
        $this->assertSame('system', $payload['tenant_ref']);
        $this->assertSame([['recipient_ref' => 'user_1', 'email' => 'coach@example.invalid']], $payload['recipients']);
        $this->assertArrayHasKey('expires_at', $payload);
        Queue::assertPushed(DeliverOutboxMessage::class, fn ($job) => $job->outboxMessageId === $message->id);
    }

    public function test_the_payload_is_encrypted_at_rest(): void
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

    public function test_queuing_the_same_dedupe_key_twice_keeps_one_message(): void
    {
        Queue::fake();

        $first = $this->queueLoginLink($this->outbox());
        $second = $this->queueLoginLink($this->outbox());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, OutboxMessage::count());
        Queue::assertPushed(DeliverOutboxMessage::class, 1);
    }

    public function test_demo_mode_refuses_real_addresses_without_naming_them(): void
    {
        Queue::fake();

        try {
            $this->queueLoginLink($this->outbox(demo: true), email: 'parent@example.com');
            $this->fail('A real address was accepted in demo mode');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('parent@example.com', $e->getMessage());
        }
        $this->assertSame(0, OutboxMessage::count());
    }

    public function test_demo_mode_accepts_invalid_domain_addresses(): void
    {
        Queue::fake();

        $this->queueLoginLink($this->outbox(demo: true), email: 'coach@EXAMPLE.invalid');

        $this->assertSame(1, OutboxMessage::count());
    }
}
