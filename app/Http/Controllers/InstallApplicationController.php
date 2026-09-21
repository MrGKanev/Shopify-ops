<?php

namespace App\Http\Controllers;

use App\Http\Requests\InstallApplicationRequest;
use App\Support\InitialApplicationInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class InstallApplicationController extends Controller
{
    public function create(InitialApplicationInstaller $installer): View
    {
        abort_if($installer->isInstalled(), 404);

        return view('install.create', [
            'sqliteDatabasePath' => database_path('database.sqlite'),
        ]);
    }

    public function store(InstallApplicationRequest $request, InitialApplicationInstaller $installer): RedirectResponse
    {
        abort_if($installer->isInstalled(), 404);

        try {
            $installer->install($request->validated());
        } catch (Throwable $exception) {
            Log::error('Application installation failed.', ['exception' => $exception]);

            return back()->withInput($request->except(['password', 'password_confirmation', 'db_password', 'shopify_access_token', 'shipstation_api_key', 'shipstation_api_secret']))
                ->withErrors(['installation' => 'Installation could not be completed. Check the database details and that the application can write its .env file.']);
        }

        return redirect()->route('login')->with('status', 'Installation complete. You can now sign in.');
    }
}
