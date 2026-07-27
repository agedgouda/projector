<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Features;

class LoginController extends Controller
{
    /**
     * The mobile app's own login screen. Submits to Fortify's normal POST /login — this is
     * a different screen, not a different auth mechanism, so session auth, 2FA, and the
     * "intended URL" redirect (back to /app) all work exactly as they do on the web.
     */
    public function create(Request $request): \Inertia\Response
    {
        return Inertia::render('Mobile/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => Features::enabled(Features::registration()),
            'status' => $request->session()->get('status'),
        ]);
    }
}
