<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->gate();

        parent::boot();

        Horizon::auth(function (Request $request): bool {
            $user = $request->user();

            return $user instanceof User && $user->isAdministrator();
        });

        if (app()->bound('csp-nonce')) {
            Horizon::cspNonce((string) app('csp-nonce'));
        }
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => $user?->isAdministrator() ?? false);
    }
}
