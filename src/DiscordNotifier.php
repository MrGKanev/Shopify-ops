<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends concise operational summaries to Discord via Incoming Webhooks.
 */
class DiscordNotifier
{
    private readonly Client $http;

    public function __construct(
        private readonly string $webhookUrl,
        ?HandlerStack $stack = null
    ) {
        if (trim($webhookUrl) === '') {
            throw new InvalidArgumentException('Discord webhook URL is empty.');
        }

        $this->http = new Client([
            'handler' => $stack ?? HandlerStack::create(),
            'timeout' => 8,
        ]);
    }

    public static function isConfigured(): bool
    {
        return trim((string) getenv('DISCORD_WEBHOOK_URL')) !== '';
    }

    public static function fromEnvironment(?HandlerStack $stack = null): ?self
    {
        $url = trim((string) getenv('DISCORD_WEBHOOK_URL'));
        return $url !== '' ? new self($url, $stack) : null;
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function notifyAudit(array $summary): void
    {
        $this->send(self::auditPayload($summary));
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function notifyScan(array $summary): void
    {
        $this->send(self::scanPayload($summary));
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function notifyAuditSafely(array $summary, ?LoggerInterface $logger = null): bool
    {
        try {
            $this->notifyAudit($summary);
            return true;
        } catch (Throwable $e) {
            $logger?->warning('Discord audit notification failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public static function auditPayload(array $summary): array
    {
        $store     = (string) ($summary['store'] ?? 'Unknown store');
        $start     = (string) ($summary['start'] ?? '');
        $end       = (string) ($summary['end'] ?? '');
        $missing   = (int) ($summary['missing_count'] ?? 0);
        $found     = (int) ($summary['found'] ?? 0);
        $skipped   = (int) ($summary['skipped'] ?? 0);
        $ignored   = (int) ($summary['ignored'] ?? 0);
        $totalSs   = (int) ($summary['total_ss'] ?? 0);
        $duration  = isset($summary['duration']) ? (float) $summary['duration'] : null;
        $orders    = array_slice((array) ($summary['missing_orders'] ?? []), 0, 10);
        $moreCount = max(0, $missing - count($orders));

        $statusText = $missing > 0
            ? "{$missing} missing order" . ($missing === 1 ? '' : 's')
            : 'No missing orders';

        // 0x16a34a = 1483594 (green), 0xdc2626 = 14427686 (red)
        $color = $missing > 0 ? 14427686 : 1483594;

        $fields = [
            ['name' => 'Store',             'value' => $store,              'inline' => true],
            ['name' => 'Period',            'value' => "{$start} \u{2192} {$end}", 'inline' => true],
            ['name' => 'Missing',           'value' => (string) $missing,   'inline' => true],
            ['name' => 'Matched',           'value' => (string) $found,     'inline' => true],
            ['name' => 'Skipped',           'value' => (string) $skipped,   'inline' => true],
            ['name' => 'Ignored',           'value' => (string) $ignored,   'inline' => true],
            ['name' => 'ShipStation total', 'value' => (string) $totalSs,   'inline' => true],
        ];

        if ($duration !== null) {
            $fields[] = ['name' => 'Duration', 'value' => "{$duration}s", 'inline' => true];
        }

        $description = '';
        if ($orders !== []) {
            $lines = [];
            foreach ($orders as $order) {
                if (!is_array($order)) {
                    continue;
                }
                $label = (string) ($order['name'] ?? $order['order_number'] ?? '?');
                $total = isset($order['total_price']) ? '$' . number_format((float) $order['total_price'], 2) : '';
                $lines[] = trim("- {$label} {$total}");
            }
            if ($moreCount > 0) {
                $lines[] = "- \u{2026}and {$moreCount} more";
            }
            $description = implode("\n", $lines);
        }

        $embed = [
            'title'  => "Shopify Ops audit: {$statusText}",
            'color'  => $color,
            'fields' => $fields,
        ];
        if ($description !== '') {
            $embed['description'] = $description;
        }

        return [
            'content' => "Shopify Ops audit for **{$store}**: {$statusText}",
            'embeds'  => [$embed],
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public static function scanPayload(array $summary): array
    {
        $tool    = (string) ($summary['tool'] ?? 'scan');
        $rows    = (int) ($summary['rows_found'] ?? 0);
        $scanned = $summary['scanned'] ?? null;
        $start   = (string) ($summary['start'] ?? '');
        $end     = (string) ($summary['end'] ?? '');

        $fields = [
            ['name' => 'Tool',       'value' => $tool,          'inline' => true],
            ['name' => 'Rows found', 'value' => (string) $rows, 'inline' => true],
        ];
        if ($scanned !== null) {
            $fields[] = ['name' => 'Scanned', 'value' => (string) $scanned, 'inline' => true];
        }
        if ($start || $end) {
            $fields[] = ['name' => 'Period', 'value' => "{$start} \u{2192} {$end}", 'inline' => true];
        }

        return [
            'content' => "Shopify Ops scan **{$tool}**: {$rows} row" . ($rows === 1 ? '' : 's') . ' found',
            'embeds'  => [[
                'title'  => "Shopify Ops scan: {$tool}",
                'color'  => $rows > 0 ? 14427686 : 1483594,
                'fields' => $fields,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(array $payload): void
    {
        $response = $this->http->request('POST', $this->webhookUrl, [
            'http_errors' => false,
            'headers'     => ['Content-Type' => 'application/json'],
            'json'        => $payload,
        ]);

        $this->assertOk($response);
    }

    private function assertOk(ResponseInterface $response): void
    {
        $code = $response->getStatusCode();
        if ($code < 200 || $code >= 300) {
            $raw = (string) $response->getBody();
            throw new RuntimeException("Discord webhook error {$code}: {$raw}");
        }
    }
}
