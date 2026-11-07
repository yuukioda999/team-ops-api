<?php

namespace App\Http\Requests\AuditLogs;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 監査ログの一覧(AC-004-12)。before を渡すと、その ID より古いものを返す。
 */
final class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && $this->user()?->can('viewAuditLogs', $team) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['before' => ['nullable', 'integer', 'min:1', 'max:999999999999999999']];
    }

    public function before(): ?int
    {
        $value = $this->validated('before');

        return $value === null ? null : (int) $value;
    }
}
