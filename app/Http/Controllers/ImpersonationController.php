<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImpersonationController extends Controller
{
    /**
     * Start impersonating a user. Super-admins only (see route middleware) — the target may
     * be anyone, including another super-admin.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        abort_if($request->session()->has('impersonator_id'), 409, 'Already impersonating a user — stop first.');
        abort_if($user->id === $request->user()->id, 422, "You can't impersonate yourself.");

        $admin = $request->user();

        Log::info('Impersonation started', [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'target_id' => $user->id,
            'target_email' => $user->email,
        ]);

        $request->session()->put('impersonator_id', $admin->id);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /**
     * Stop impersonating and return to the admin's own account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull('impersonator_id');
        abort_unless(is_int($impersonatorId), 404);

        $admin = User::findOrFail($impersonatorId);
        $target = $request->user();

        Log::info('Impersonation stopped', [
            'admin_id' => $admin->id,
            'target_id' => $target?->id,
        ]);

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
