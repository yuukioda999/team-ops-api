<?php

namespace Tests\Feature\Players;

use App\Enums\TeamRole;
use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/**
 * ADR-0003:選手の表示名を、ログ・監査ログ・エラーの文言に出さない。
 */
class RosterLoggingTest extends PlayerTestCase
{
    public function test_AC_003_20_player_names_do_not_appear_in_logs_audit_logs_or_error_messages(): void
    {
        $handler = new TestHandler;
        $channel = Log::channel();
        $this->assertInstanceOf(\Illuminate\Log\Logger::class, $channel);
        $logger = $channel->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($handler);

        $parent = $this->member([TeamRole::Parent]);
        $name = '秘密之介';
        $id = (int) $this->actingAs($this->representative)->postJson($this->url(), ['display_name' => $name, 'grade' => 'e3'])->json('player.id');
        $this->actingAs($this->representative)->patchJson($this->url("/{$id}"), ['display_name' => "{$name}2"]);
        $this->actingAs($this->representative)->putJson($this->url("/{$id}/guardians"), ['membership_ids' => [$this->membershipOf($parent)->id]]);
        $this->actingAs($parent)->getJson($this->url());
        $this->actingAs($parent)->getJson($this->url("/{$id}"));

        $csv = "表示名,学年\n{$name},小3\n{$name},小3\n".str_repeat('長', 31).",小1\n{$name},小9\n";
        $responses = [
            $this->actingAs($this->representative)->post($this->url('/import/preview'), ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv)], ['Accept' => 'application/json']),
            $this->actingAs($this->representative)->post($this->url('/import'), ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv), 'file_hash' => hash('sha256', $csv)], ['Accept' => 'application/json']),
        ];
        $this->actingAs($this->representative)->deleteJson($this->url("/{$id}"));

        $logged = json_encode(array_map(fn ($r) => [$r->message, $r->context], $handler->getRecords()), JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringNotContainsString($name, $logged);
        $this->assertStringNotContainsString($name, (string) json_encode(AuditLog::pluck('details')->all(), JSON_UNESCAPED_UNICODE));

        // エラーと警告の文言には、行番号・列・選べない学年の値だけを入れ、表示名は入れない
        $errors = $responses[0]->json('errors');
        $warnings = $responses[0]->json('warnings');
        $this->assertNotEmpty($errors);
        $this->assertNotEmpty($warnings);
        $this->assertStringNotContainsString($name, (string) json_encode([$errors, $warnings], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString($name, (string) json_encode($responses[1]->json('errors'), JSON_UNESCAPED_UNICODE));
    }
}
