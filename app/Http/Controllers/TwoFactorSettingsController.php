<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class TwoFactorSettingsController extends Controller
{
    public function __invoke(): View
    {
        return view('auth.two-factor-settings');
    }
}
