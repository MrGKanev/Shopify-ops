<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttachRequestContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = Str::isUuid($request->header('X-Request-ID', '')) ? (string) $request->header('X-Request-ID') : (string) Str::uuid();
        Context::add(array_filter([
            'request_id' => $requestId,
            'route' => $request->route()?->getName(),
            'tool' => $request->route()?->getName(),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
