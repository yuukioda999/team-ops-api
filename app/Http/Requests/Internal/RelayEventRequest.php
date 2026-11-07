<?php

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * notify-relay の webhook の本文(notify-relay api/openapi.yaml の ResultsEvent・CompletedEvent)。
 * 署名は VerifyRelaySignature で確かめてある。
 */
final class RelayEventRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'regex:/^evt_[0-9A-HJKMNP-TV-Z]{26}$/'],
            'type' => ['required', 'string', Rule::in(['delivery.results', 'delivery.completed'])],
            'delivery_id' => ['required', 'string', 'regex:/^dlv_[0-9A-HJKMNP-TV-Z]{26}$/'],
            'dedupe_key' => ['required', 'string', 'max:128'],
            'results' => ['required_if:type,delivery.results', 'array', 'max:500'],
            'results.*.recipient_ref' => ['required', 'string', 'max:64'],
            'results.*.status' => ['required', 'string', Rule::in(['delivered', 'failed', 'skipped'])],
            'results.*.error_code' => ['nullable', 'string', 'max:32'],
            'results.*.occurred_at' => ['required', 'date'],
            'counts' => ['required_if:type,delivery.completed', 'array'],
            'counts.delivered' => ['required_with:counts', 'integer', 'min:0'],
            'counts.failed' => ['required_with:counts', 'integer', 'min:0'],
            'counts.skipped' => ['required_with:counts', 'integer', 'min:0'],
        ];
    }

    /**
     * 処理に使う形にそろえる。
     *
     * @return array{event_id: string, type: string, delivery_id: string, dedupe_key: string, results: list<array{recipient_ref: string, status: string, error_code: string|null, occurred_at: string}>, finished: int}
     */
    public function event(): array
    {
        /** @var array{event_id: string, type: string, delivery_id: string, dedupe_key: string, results?: list<array{recipient_ref: string, status: string, error_code?: string|null, occurred_at: string}>, counts?: array{delivered: int, failed: int, skipped: int}} $v */
        $v = $this->validated();

        return [
            'event_id' => $v['event_id'],
            'type' => $v['type'],
            'delivery_id' => $v['delivery_id'],
            'dedupe_key' => $v['dedupe_key'],
            'results' => array_map(fn (array $r) => [
                'recipient_ref' => $r['recipient_ref'],
                'status' => $r['status'],
                'error_code' => $r['error_code'] ?? null,
                'occurred_at' => $r['occurred_at'],
            ], $v['results'] ?? []),
            // 完了の通知の、最終状態になった宛先の数(受け取った結果の数と比べる)
            'finished' => isset($v['counts']) ? (int) $v['counts']['delivered'] + (int) $v['counts']['failed'] + (int) $v['counts']['skipped'] : 0,
        ];
    }
}
