<?php

namespace App\Http\Controllers\Admin;

use App\Application\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BannedIpController extends Controller
{
    public function index(LoginThrottle $throttle): View
    {
        return view('admin.banned-ips', ['bannedIps' => $throttle->bannedIps()]);
    }

    public function destroy(Request $request, LoginThrottle $throttle): RedirectResponse
    {
        $ip = (string) $request->validate(['ip' => ['required', 'ip']])['ip'];
        $throttle->unban($ip);

        return back()->with('status', "Unbanned {$ip}.");
    }
}
