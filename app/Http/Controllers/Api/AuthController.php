<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticationProvider;

class AuthController extends Controller
{
    /**
     * Issue a personal access token for the mobile app. Mirrors Fortify's own web login
     * rules — including two-factor — since this is a second front door into the same
     * accounts, not a separate, weaker one.
     */
    public function store(Request $request, TwoFactorAuthenticationProvider $twoFactor): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'required|string|max:255',
            'two_factor_code' => 'sometimes|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->two_factor_confirmed_at) {
            $code = $validated['two_factor_code'] ?? null;

            if (! is_string($code) || ! is_string($user->two_factor_secret)) {
                throw ValidationException::withMessages([
                    'two_factor_code' => ['A two-factor authentication code is required.'],
                ]);
            }

            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

            if (! is_string($secret) || ! $twoFactor->verify($secret, $code)) {
                throw ValidationException::withMessages([
                    'two_factor_code' => ['The provided two-factor authentication code was invalid.'],
                ]);
            }
        }

        $token = $user->createToken($validated['device_name']);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Revoke the token used to make this request (i.e. log out this device only).
     */
    public function destroy(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out.']);
    }
}
