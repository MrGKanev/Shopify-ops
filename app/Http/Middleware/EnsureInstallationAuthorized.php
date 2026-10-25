<?php

namespace App\Http\Middleware;

use App\Support\InitialApplicationInstaller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstallationAuthorized
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('security.installation_token');
        abort_if($token === '' || app(InitialApplicationInstaller::class)->isInstalled(), 404);
        if (! hash_equals($token, (string) $request->getPassword())) {
            return response('Installation authorization required.', 401, ['WWW-Authenticate' => 'Basic realm="Installation"']);
        }

        return $next($request);
    }
}
