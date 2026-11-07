<?php

namespace Tests\Feature\Notices;

use App\Enums\DeliveryStatus;
use App\Enums\TeamRole;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * notify-relay の送信結果の受け取り(AC-006-08)、照合(AC-006-09)、メールが届かないアドレス(AC-006-14・16・17)。ADR-0019・0020。
 */
class DeliveryResultTest extends NoticeTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function send(array $overrides = []): Notice
    {
        $id = (int) $this->actingAs($this->representative)->postJson($this->url(), $this->input($overrides))->assertCreated()->json('notice.id');

        return Notice::findOrFail($id);
    }

    private function key(Notice $notice, int $part = 1): string
    {
        return "team:{$this->team->id}:notice:{$notice->id}:part:{$part}";
    }

    private function recipient(Notice $notice, User $user): NoticeRecipient
    {
        return NoticeRecipient::where('notice_id', $notice->id)->where('user_id', $user->id)->sole();
    }

    public function test_AC_006_08_results_update_each_recipient_once_even_if_sent_twice(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $notice = $this->send();
        $this->assertSame(DeliveryStatus::Pending, $this->recipient($notice, $parent)->delivery_status);
        $this->assertSame(self::deliveryId(1), OutboxMessage::where('dedupe_key', $this->key($notice))->sole()->delivery_id);

        $results = [$parent->id => ['failed', 'provider_rejected'], $this->representative->id => ['delivered']];
        $this->results($this->key($notice), $results, eventId: 'evt_01JC9ZB4X7Q2N6R8T0V2W4Y6Z8')->assertNoContent();
        // 同じイベントがもう一度届いても、件数も状態も変わらない
        $this->results($this->key($notice), $results, eventId: 'evt_01JC9ZB4X7Q2N6R8T0V2W4Y6Z8')->assertNoContent();

        $this->assertSame(DeliveryStatus::Failed, $this->recipient($notice, $parent)->delivery_status);
        $this->assertSame('provider_rejected', $this->recipient($notice, $parent)->delivery_error);
        $this->assertSame(DeliveryStatus::Delivered, $this->recipient($notice, $this->representative)->delivery_status);
        $this->assertSame(2, OutboxMessage::where('dedupe_key', $this->key($notice))->sole()->results_received);
    }

    public function test_AC_006_08_an_older_result_does_not_overwrite_a_newer_one(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $notice = $this->send();

        $this->results($this->key($notice), [$parent->id => ['delivered']], now()->addMinutes(5)->toIso8601String())->assertNoContent();
        $this->results($this->key($notice), [$parent->id => ['failed', 'provider_unavailable']], now()->addMinute()->toIso8601String())->assertNoContent();

        $this->assertSame(DeliveryStatus::Delivered, $this->recipient($notice, $parent)->delivery_status);
    }

    public function test_AC_006_08_a_wrong_signature_or_old_timestamp_is_401_and_changes_nothing(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $notice = $this->send();
        $event = ['event_id' => 'evt_01JC9ZB4X7Q2N6R8T0V2W4Y6Z8', 'type' => 'delivery.results', 'delivery_id' => self::deliveryId(1), 'dedupe_key' => $this->key($notice),
            'results' => [['recipient_ref' => "user_{$parent->id}", 'status' => 'delivered', 'attempts' => 1, 'occurred_at' => now()->toIso8601String()]]];

        $this->relayEvent($event, secret: str_repeat('x', 32))->assertUnauthorized();
        $this->relayEvent($event, timestamp: time() - 301)->assertUnauthorized();

        $this->assertSame(DeliveryStatus::Pending, $this->recipient($notice, $parent)->delivery_status);
        $this->assertSame(0, DB::table('relay_events')->count());
    }

    public function test_AC_006_08_results_of_other_mails_and_other_teams_are_ignored(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $notice = $this->send();

        // ログインのメールの結果
        $this->results('login-link:abc12345', [$parent->id => ['failed', 'invalid_address']])->assertNoContent();
        // 別のチームのIDを付けたキー
        $this->results("team:01JC9ZB4X7Q2N6R8T0V2W4Y6Z8:notice:{$notice->id}:part:1", [$parent->id => ['failed', 'provider_rejected']])->assertNoContent();

        $this->assertSame(DeliveryStatus::Pending, $this->recipient($notice, $parent)->delivery_status);
        $this->assertNull($parent->fresh()?->email_undeliverable_at);
    }

    public function test_AC_006_08_the_completed_event_is_recorded(): void
    {
        $notice = $this->send();
        $this->relayEvent(['event_id' => 'evt_01JC9ZB4X7Q2N6R8T0V2W4Y6Z9', 'type' => 'delivery.completed', 'delivery_id' => self::deliveryId(1), 'dedupe_key' => $this->key($notice),
            'counts' => ['pending' => 0, 'delivered' => 1, 'failed' => 0, 'skipped' => 0], 'completed_at' => now()->toIso8601String(), 'sent_at' => now()->toIso8601String()])->assertNoContent();

        $this->assertNotNull(OutboxMessage::where('dedupe_key', $this->key($notice))->sole()->relay_completed_at);
    }

    public function test_AC_006_09_deliveries_without_results_for_an_hour_are_reconciled_and_mismatches_are_alerted(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $notice = $this->send();
        $this->results($this->key($notice), [$parent->id => ['delivered']])->assertNoContent();
        $log = Log::spy();

        // 1時間たつまでは照会しない
        $this->travel(59)->minutes();
        $this->runCommand('notices:reconcile-deliveries');
        $this->assertSame(0, DB::table('delivery_reconciliations')->count());

        // notify-relay では2人とも終わっているのに、結果は1人分しか届いていない
        $this->travel(2)->minutes();
        $this->relayStatus = ['delivery_id' => self::deliveryId(1), 'dedupe_key' => $this->key($notice), 'state' => 'completed', 'counts' => ['pending' => 0, 'delivered' => 2, 'failed' => 0, 'skipped' => 0], 'accepted_at' => now()->toIso8601String(), 'completed_at' => now()->toIso8601String()];
        $this->runCommand('notices:reconcile-deliveries');

        $record = DB::table('delivery_reconciliations')->sole();
        $this->assertSame(2, (int) $record->relay_finished);
        $this->assertSame(1, (int) $record->results_received);
        $log->shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['relay_finished'] === 2 && $context['results_received'] === 1);

        // 残りの結果が届けば、次の照合で一致して終わる
        $this->results($this->key($notice), [$this->representative->id => ['delivered']])->assertNoContent();
        $this->travel(1)->hours();
        $this->runCommand('notices:reconcile-deliveries');
        $this->assertSame(1, DB::table('delivery_reconciliations')->count());
        $this->assertNotNull(OutboxMessage::where('dedupe_key', $this->key($notice))->sole()->reconcile_stopped_at);
    }

    public function test_AC_006_09_a_delivery_deleted_after_7_days_is_no_longer_reconciled(): void
    {
        $notice = $this->send();
        $this->relayStatus = null;
        $this->travel(2)->hours();
        $this->runCommand('notices:reconcile-deliveries');

        $this->assertNotNull(OutboxMessage::where('dedupe_key', $this->key($notice))->sole()->reconcile_stopped_at);
        $this->assertSame(0, DB::table('delivery_reconciliations')->count());
    }

    public function test_AC_006_16_AC_006_17_an_invalid_address_stops_notice_mail_until_the_address_changes(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $first = $this->send();
        $this->results($this->key($first), [$parent->id => ['failed', 'invalid_address']])->assertNoContent();

        $parent = $parent->fresh() ?? $parent;
        $this->assertNotNull($parent->email_undeliverable_at);
        $this->actingAs($parent)->getJson('/api/me')->assertJsonPath('email_undeliverable', true);

        // 次のお知らせは、この人にはメールを送らない(アプリには届く)
        $this->relayed = [];
        $second = $this->send(['title' => '次の連絡']);
        $this->assertNotContains($parent->email, $this->deliveredTo()[0]);
        $this->assertSame(DeliveryStatus::Skipped, $this->recipient($second, $parent)->delivery_status);
        $this->assertSame('undeliverable_address', $this->recipient($second, $parent)->delivery_error);
        $this->actingAs($parent)->getJson($this->url("/{$second->id}"))->assertOk();

        // ログインのメールは送る(アドレスを変えるのに要る)
        $this->relayed = [];
        $this->postJson('/api/auth/login-links', ['email' => $parent->email])->assertAccepted();
        $this->assertNotSame([], $this->relayed);

        // アドレスを変えると、表示が消えて送信対象に戻る
        $parent->forceFill(['email' => 'new-address@example.invalid', 'email_changed_at' => now(), 'email_undeliverable_at' => null])->save();
        $this->actingAs($parent)->getJson('/api/me')->assertJsonPath('email_undeliverable', false);
        $this->relayed = [];
        $this->send(['title' => '変更後の連絡']);
        $this->assertContains('new-address@example.invalid', $this->deliveredTo()[0]);
    }

    public function test_AC_006_17_a_late_result_for_the_old_address_does_not_stop_the_new_one(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $notice = $this->send();
        $sentAt = now()->toIso8601String();
        $this->travel(10)->minutes();
        $parent->forceFill(['email' => 'new-address@example.invalid', 'email_changed_at' => now()])->save();

        $this->results($this->key($notice), [$parent->id => ['failed', 'invalid_address']], $sentAt)->assertNoContent();

        $this->assertNull($parent->fresh()?->email_undeliverable_at);
    }

    public function test_AC_006_16_other_failures_do_not_mark_the_address(): void
    {
        $parent = $this->member([TeamRole::Parent], '保護者');
        $notice = $this->send();
        $this->results($this->key($notice), [$parent->id => ['failed', 'provider_rejected']])->assertNoContent();

        $this->assertNull($parent->fresh()?->email_undeliverable_at);
    }
}
