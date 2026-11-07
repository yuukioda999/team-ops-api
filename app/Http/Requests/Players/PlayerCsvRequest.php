<?php

namespace App\Http\Requests\Players;

use App\Support\Roster\PlayerCsv;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * 名簿の CSV(AC-003-14・17)。確定のときは、確認で返したファイルのハッシュも送る(ADR-0014)。
 */
final class PlayerCsvRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'file' => ['required', 'file', 'max:'.(PlayerCsv::MAX_BYTES / 1024)],
        ];
        if ($this->routeIs('players.import')) {
            $rules['file_hash'] = ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['file.max' => 'CSVファイルは1MBまでです。'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => 'CSVファイル', 'file_hash' => '確認したファイル'];
    }

    public function bytes(): string
    {
        $file = $this->file('file');
        if (! $file instanceof UploadedFile) {
            return '';
        }

        return (string) $file->get();
    }

    public function confirmedHash(): string
    {
        return (string) $this->validated('file_hash');
    }
}
