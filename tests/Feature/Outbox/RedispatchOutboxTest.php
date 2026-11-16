<?php

namespace Tests\Feature\Outbox;

use App\Enums\OutboxStatus;
use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-0002:送り損ねた依頼を拾い直す(plan.md D2)。
 */
class RedispatchOutboxTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(string $key, array $attributes): OutboxMessage
    {
        $message = OutboxMessage::create(['kind' => 'login_link', 'dedupe_key' => $key, 'payload' => ['x' => 1]]);
        $message->forceFill($attributes)->save();

        return $message;
    }

    public function test_ADR_0002_only_pending_messages_whose_time_has_come_are_queued_again(): void
    {
        Queue::fake();
        $due = $this->message('key-due-0001', ['next_attempt_at' => now()->subSecond()]);
        $neverQueued = $this->message('key-lost-0001', ['next_attempt_at' => null, 'created_at' => now()->subMinutes(2)]);
        $this->message('key-later-0001', ['next_attempt_at' => now()->addMinute()]);
        $this->message('key-new-0001', ['next_attempt_at' => null, 'created_at' => now()]);
        $this->message('key-sent-0001', ['status' => OutboxStatus::Sent, 'next_attempt_at' => now()->subMinute()]);
        $this->message('key-failed-0001', ['status' => OutboxStatus::Failed, 'next_attempt_at' => now()->subMinute()]);

        $this->assertSame(0, $this->withoutMockingConsoleOutput()->artisan('outbox:redispatch'));

        Queue::assertPushed(DeliverOutboxMessage::class, 2);
        Queue::assertPushed(DeliverOutboxMessage::class, fn ($job) => $job->outboxMessageId === $due->id);
        Queue::assertPushed(DeliverOutboxMessage::class, fn ($job) => $job->outboxMessageId === $neverQueued->id);
    }

    public function test_AC_009_12_with_now_messages_waiting_for_their_time_are_queued_again_at_once(): void
    {
        Queue::fake();
        $later = $this->message('key-later-0002', ['next_attempt_at' => now()->addMinutes(25), 'attempts' => 5]);
        $due = $this->message('key-due-0002', ['next_attempt_at' => now()->subSecond()]);
        $sent = $this->message('key-sent-0002', ['status' => OutboxStatus::Sent, 'next_attempt_at' => now()->addMinute()]);
        $gaveUp = $this->message('key-gaveup-0002', ['status' => OutboxStatus::Failed, 'next_attempt_at' => now()->addMinute()]);

        $this->assertSame(0, $this->withoutMockingConsoleOutput()->artisan('outbox:redispatch', ['--now' => true]));

        Queue::assertPushed(DeliverOutboxMessage::class, 2);
        Queue::assertPushed(DeliverOutboxMessage::class, fn ($job) => $job->outboxMessageId === $later->id);
        Queue::assertPushed(DeliverOutboxMessage::class, fn ($job) => $job->outboxMessageId === $due->id);
        // 送り直しの回数は変えない。送ったもの・諦めたものは触らない
        $this->assertSame(5, $later->refresh()->attempts);
        $this->assertTrue($sent->refresh()->next_attempt_at?->isFuture());
        $this->assertTrue($gaveUp->refresh()->next_attempt_at?->isFuture());
    }

    public function test_AC_009_12_without_now_messages_waiting_for_their_time_are_left_alone(): void
    {
        Queue::fake();
        $this->message('key-later-0003', ['next_attempt_at' => now()->addMinutes(25)]);

        $this->assertSame(0, $this->withoutMockingConsoleOutput()->artisan('outbox:redispatch'));

        Queue::assertNothingPushed();
    }

    public function test_the_command_runs_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e->command, 'outbox:redispatch') && $e->expression === '* * * * *'));
    }
}
