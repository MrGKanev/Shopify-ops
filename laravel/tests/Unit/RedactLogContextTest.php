<?php

namespace Tests\Unit;

use App\Support\RedactLogContext;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class RedactLogContextTest extends TestCase
{
    public function test_sensitive_context_is_filtered_and_urls_lose_query_strings(): void
    {
        $record = new LogRecord(now()->toDateTimeImmutable(), 'test', Level::Warning, 'Failed', [
            'store_id' => 7,
            'access_token' => 'secret-token',
            'callback_url' => 'https://example.test/callback?token=secret-token',
        ]);

        $redacted = (new RedactLogContext)->redact($record);

        $this->assertSame(7, $redacted->context['store_id']);
        $this->assertSame('[Filtered]', $redacted->context['access_token']);
        $this->assertSame('https://example.test/callback', $redacted->context['callback_url']);
    }
}
