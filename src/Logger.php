<?php
declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Simple PSR-3 file logger. Writes to logs/app.log, rotates at 10 MB.
 */
class Logger extends AbstractLogger
{
    private static ?Logger $instance = null;
    private string $path;
    private int $maxBytes;

    private function __construct(string $logDir, int $maxBytes = 10_485_760)
    {
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->path     = rtrim($logDir, '/') . '/app.log';
        $this->maxBytes = $maxBytes;
    }

    public static function getInstance(string $logDir = '', int $maxBytes = 10_485_760): self
    {
        if (self::$instance === null) {
            self::$instance = new self($logDir ?: __DIR__ . '/../logs', $maxBytes);
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function log(mixed $level, \Stringable|string $message, array $context = []): void
    {
        $this->rotate();

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper((string) $level),
            $this->interpolate((string) $message, $context),
            isset($context['exception']) ? ' — ' . $context['exception'] : ''
        );

        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if ($key === 'exception') continue;
            $replace['{' . $key . '}'] = (string) $val;
        }
        return strtr($message, $replace);
    }

    private function rotate(): void
    {
        if (file_exists($this->path) && filesize($this->path) > $this->maxBytes) {
            rename($this->path, $this->path . '.' . date('Ymd-His') . '.bak');
        }
    }
}
