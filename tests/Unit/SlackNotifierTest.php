<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/SlackNotifier.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class SlackNotifierTest extends TestCase
{
    public function testAuditPayloadIncludesSummaryFields(): void
    {
        $payload = SlackNotifier::auditPayload([
            'store'          => 'example.myshopify.com',
            'start'          => '2026-06-01',
            'end'            => '2026-06-19',
            'missing_count'  => 1,
            'missing_orders' => [['name' => '#1001', 'total_price' => '49.95']],
            'found'          => 20,
            'skipped'        => 3,
            'ignored'        => 2,
            'total_ss'       => 25,
            'duration'       => 4.2,
        ]);

        $this->assertSame('Shopify Ops audit for example.myshopify.com: 1 missing order', $payload['text']);
        $this->assertSame('blocks', array_key_last($payload));
        $this->assertStringContainsString('Shopify Ops audit: 1 missing order', $payload['blocks'][0]['text']['text']);
        $this->assertStringContainsString('#1001', $payload['blocks'][2]['text']['text']);
    }

    public function testSendPostsJsonPayload(): void
    {
        $history = [];
        $mock    = new MockHandler([new Response(200, [], 'ok')]);
        $stack   = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $notifier = new SlackNotifier('https://hooks.slack.test/services/x/y/z', $stack);
        $notifier->send(['text' => 'hello']);

        $this->assertCount(1, $history);
        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Content-Type'));
        $this->assertSame(['text' => 'hello'], json_decode((string) $history[0]['request']->getBody(), true));
    }

    public function testScanPayloadIncludesRowsFound(): void
    {
        $payload = SlackNotifier::scanPayload([
            'tool' => 'scan_sla',
            'rows_found' => 3,
            'scanned' => 50,
            'start' => '2026-06-01',
            'end' => '2026-06-19',
        ]);

        $this->assertSame('Shopify Ops scan scan_sla: 3 rows found', $payload['text']);
        $this->assertStringContainsString('scan_sla', $payload['blocks'][0]['text']['text']);
    }

    public function testSendThrowsOnSlackError(): void
    {
        $mock     = new MockHandler([new Response(500, [], 'bad')]);
        $notifier = new SlackNotifier('https://hooks.slack.test/services/x/y/z', HandlerStack::create($mock));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Slack webhook error 500');

        $notifier->send(['text' => 'hello']);
    }
}
