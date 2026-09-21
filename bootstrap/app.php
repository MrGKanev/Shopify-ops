<?php

$environmentFile = dirname(__DIR__).'/.env';
$configuredKey = $_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? getenv('APP_KEY');
if (! is_string($configuredKey) || $configuredKey === '') {
    if (! file_exists($environmentFile) && is_readable(dirname(__DIR__).'/.env.example')) {
        copy(dirname(__DIR__).'/.env.example', $environmentFile);
    }

    if (is_writable($environmentFile)) {
        $environment = file_get_contents($environmentFile);
        if (is_string($environment) && preg_match('/^APP_KEY=(?:\s|""|\'\')*$/m', $environment) === 1) {
            $environment = preg_replace(
                '/^APP_KEY=.*$/m',
                'APP_KEY=base64:'.base64_encode(random_bytes(32)),
                $environment,
            );
            $environment = preg_replace('/^SESSION_DRIVER=.*$/m', 'SESSION_DRIVER=file', (string) $environment);
            $environment = preg_replace('/^CACHE_STORE=.*$/m', 'CACHE_STORE=file', (string) $environment);
            file_put_contents($environmentFile, $environment);
        }
    }
}

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AttachRequestContext;
use App\Http\Middleware\EnsureActiveStore;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Spatie\Csp\AddCspHeaders;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([AttachRequestContext::class, AddCspHeaders::class, AddSecurityHeaders::class]);
        $middleware->validateCsrfTokens(except: ['webhooks/shopify/*']);

        $trustedProxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->alias([
            'active.store' => EnsureActiveStore::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request): string => route('login'));
        $middleware->redirectUsersTo(fn (Request $request): string => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
