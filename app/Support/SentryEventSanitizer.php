<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;

class SentryEventSanitizer
{
    private const REDACTED = '[Filtered]';

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'access_token',
        'address',
        'api_key',
        'authorization',
        'cookie',
        'credential',
        'customer',
        'email',
        'first_name',
        'last_name',
        'password',
        'phone',
        'secret',
        'token',
    ];

    public static function sanitizeEvent(Event $event): Event
    {
        $request = $event->getRequest();
        $event->setRequest(array_filter([
            'method' => $request['method'] ?? null,
            'url' => self::withoutQueryString($request['url'] ?? null),
        ]));
        $event->setUser(null);
        $event->setExtra(self::sanitizeArray($event->getExtra()));
        $event->setTags(self::sanitizeArray($event->getTags()));
        if ($event->getTransaction() !== null) {
            $event->setTransaction(self::sanitizeText($event->getTransaction()));
        }
        if ($event->getMessage() !== null) {
            $event->setMessage(self::sanitizeText($event->getMessage()), self::sanitizeArray($event->getMessageParams()),
                $event->getMessageFormatted() === null ? null : self::sanitizeText($event->getMessageFormatted()));
        }
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::sanitizeText($exception->getValue()));
            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frame->setVars(self::sanitizeArray($frame->getVars()));
            }
        }
        foreach ($event->getStacktrace()?->getFrames() ?? [] as $frame) {
            $frame->setVars(self::sanitizeArray($frame->getVars()));
        }
        foreach ($event->getSpans() as $span) {
            if ($span->getDescription() !== null) {
                $span->setDescription(self::sanitizeText($span->getDescription()));
            }
            $span->setData(self::sanitizeArray($span->getData()));
            $span->setTags(self::sanitizeArray($span->getTags()));
        }
        $event->setBreadcrumb(array_map(self::sanitizeBreadcrumb(...), $event->getBreadcrumbs()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, self::sanitizeArray($context));
        }

        return $event;
    }

    public static function sanitizeBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        if ($breadcrumb->getMessage() !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::sanitizeText($breadcrumb->getMessage()));
        }
        foreach (self::sanitizeArray($breadcrumb->getMetadata()) as $key => $value) {
            $breadcrumb = $breadcrumb->withMetadata($key, $value);
        }

        return $breadcrumb;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function sanitizeArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = self::sanitizeArray($value);
            }

            if (is_string($value) && self::isUrlKey((string) $key)) {
                $values[$key] = self::withoutQueryString($value);
            } elseif (is_string($value)) {
                $values[$key] = self::sanitizeText($value);
            } elseif ($value instanceof \Throwable) {
                $values[$key] = ['exception_type' => $value::class, 'message' => self::sanitizeText($value->getMessage())];
            }
        }

        return $values;
    }

    public static function sanitizeText(string $text): string
    {
        return preg_replace([
            '~(/webhooks/shipstation/[^/\s?#]+/)[^\s/?#"\'<>]+~i',
            '~(/reset-password/)[^\s/?#"\'<>]+~i',
            '~(https://hooks\.slack\.com/services/)[^\s"\'<>]+~i',
            '~(https://(?:discord\.com|discordapp\.com)/api/webhooks/)[^\s"\'<>]+~i',
            '~([?&](?:token|key|api_key|access_token|secret|password)=)[^&\s"\'<>]+~i',
        ], '$1'.self::REDACTED, $text) ?? self::REDACTED;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower(str_replace(['-', '.'], '_', $key));

        return collect(self::SENSITIVE_KEYS)->contains(
            fn (string $sensitiveKey): bool => str_contains($normalizedKey, $sensitiveKey),
        );
    }

    private static function isUrlKey(string $key): bool
    {
        $normalizedKey = strtolower(str_replace(['-', '.'], '_', $key));

        return $normalizedKey === 'url'
            || $normalizedKey === 'uri'
            || str_ends_with($normalizedKey, '_url')
            || str_ends_with($normalizedKey, '_uri');
    }

    private static function withoutQueryString(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        return self::sanitizeText(strtok($url, '?') ?: $url);
    }
}
