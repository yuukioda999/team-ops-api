<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;
use Tests\TestCase;

/**
 * AC-000-04:個人情報を含む処理でエラーが起きても、ログに個人情報が出ない。
 */
class LogRedactionTest extends TestCase
{
    private TestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'single']);
        $channel = Log::channel('single');
        $this->assertInstanceOf(\Illuminate\Log\Logger::class, $channel);
        $logger = $channel->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $this->handler = new TestHandler;
        $logger->pushHandler($this->handler);
    }

    private function written(): string
    {
        return json_encode(array_map(
            fn ($record) => [$record->message, $record->context, $record->extra],
            $this->handler->getRecords(),
        ), JSON_UNESCAPED_UNICODE) ?: '';
    }

    public function test_AC_000_04_email_addresses_in_messages_are_masked(): void
    {
        Log::channel('single')->error('Failed to send to coach@example.invalid');

        $this->assertStringNotContainsString('coach@example.invalid', $this->written());
        $this->assertStringContainsString('Failed to send to [redacted]', $this->written());
    }

    public function test_AC_000_04_personal_data_fields_in_context_are_masked(): void
    {
        Log::channel('single')->warning('Login link requested', [
            'email' => 'coach@example.invalid',
            'display_name' => '山田 太郎',
            'token' => 'secret-token-value',
            'nested' => ['note' => 'contact parent@example.invalid', 'team_id' => '01JTEAM'],
        ]);

        $written = $this->written();
        foreach (['coach@example.invalid', '山田', 'secret-token-value', 'parent@example.invalid'] as $secret) {
            $this->assertStringNotContainsString($secret, $written);
        }
        $this->assertStringContainsString('01JTEAM', $written, 'Values that are not personal data stay in the log');
    }

    public function test_AC_000_04_fields_whose_names_contain_personal_data_terms_are_masked(): void
    {
        Log::channel('single')->info('Fields with various names', [
            'user_email' => 'a@example.invalid',
            'displayName' => '山田 太郎',
            'login-token' => 'secret-token-value',
            'recipients' => ['b@example.invalid'],
            'team_name' => 'つくばFC',
        ]);

        $written = $this->written();
        foreach (['a@example.invalid', '山田', 'secret-token-value', 'b@example.invalid'] as $secret) {
            $this->assertStringNotContainsString($secret, $written);
        }
        $this->assertStringContainsString('つくばFC', $written, 'Team names are not personal data');
    }

    public function test_AC_000_04_tokens_in_urls_and_url_encoded_addresses_are_masked(): void
    {
        Log::channel('single')->info('Request', [
            'url' => 'http://localhost:5173/login/verify?token=plain-login-token&from=mail',
            'query' => 'email=coach%40example.invalid',
        ]);

        $written = $this->written();
        $this->assertStringNotContainsString('plain-login-token', $written);
        $this->assertStringNotContainsString('coach%40example.invalid', $written);
        $this->assertStringContainsString('from=mail', $written);
    }

    public function test_AC_000_04_exceptions_are_logged_without_personal_data(): void
    {
        $previous = new RuntimeException('SMTP rejected coach@example.invalid');
        $exception = new RuntimeException('Delivery failed for coach@example.invalid', 0, $previous);

        Log::channel('single')->error('Delivery failed', ['exception' => $exception]);

        $written = $this->written();
        $this->assertStringNotContainsString('coach@example.invalid', $written);
        $this->assertStringContainsString(RuntimeException::class, $written);
    }

    public function test_AC_000_04_reported_exceptions_do_not_leak_personal_data(): void
    {
        report(new RuntimeException('Could not encrypt display name for coach@example.invalid'));

        $this->assertStringNotContainsString('coach@example.invalid', $this->written());
        $this->assertNotEmpty($this->handler->getRecords());
    }
}
