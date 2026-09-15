<?php

namespace App\Support;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

class RedactLogContext
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor($this->redact(...));
    }

    public function redact(LogRecord $record): LogRecord
    {
        return $record->with(
            context: SentryEventSanitizer::sanitizeArray($record->context),
            extra: SentryEventSanitizer::sanitizeArray($record->extra),
        );
    }
}
