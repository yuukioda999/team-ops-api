<?php

namespace App\Support\Queue;

use App\Support\Logging\RedactPersonalData;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\Date;

/**
 * 失敗したジョブの記録(failed_jobs)に、個人情報を伏せた例外を保存する(AC-000-04)。
 *
 * 標準の実装は例外を文字列にしてそのまま保存するため、ログの伏せ字処理を通らない。
 * ジョブには個人情報を持たせず、IDだけを渡すこと(payload は再実行に使うため伏せられない)。
 */
final class RedactingFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    public function log($connection, $queue, $payload, $exception)
    {
        $decoded = json_decode($payload, true);
        $uuid = is_array($decoded) && is_string($decoded['uuid'] ?? null) ? $decoded['uuid'] : null;

        $this->getTable()->insert([
            'uuid' => $uuid,
            'connection' => $connection,
            'queue' => $queue,
            'payload' => $payload,
            'exception' => RedactPersonalData::redactString(
                (string) mb_convert_encoding((string) $exception, 'UTF-8'),
            ),
            'failed_at' => Date::now(),
        ]);

        return $uuid;
    }
}
