<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * AC-000-04:失敗したジョブの記録(failed_jobs)にも個人情報が残らない。
 */
class FailedJobRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_AC_000_04_a_failed_job_is_recorded_without_personal_data(): void
    {
        $payload = json_encode(['uuid' => 'c3f6b7d0-0000-4000-8000-000000000001', 'displayName' => 'job']) ?: '';

        app('queue.failer')->log(
            'database',
            'default',
            $payload,
            new RuntimeException('Failed to send /login/verify?token=plain-token to coach@example.invalid'),
        );

        $exception = (string) DB::table('failed_jobs')->value('exception');
        $this->assertStringContainsString(RuntimeException::class, $exception);
        $this->assertStringNotContainsString('coach@example.invalid', $exception);
        $this->assertStringNotContainsString('plain-token', $exception);
    }
}
