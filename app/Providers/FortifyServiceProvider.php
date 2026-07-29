<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();

        $this->app->instance(LogoutResponse::class, new class implements LogoutResponse
        {
            public function toResponse($request): \Symfony\Component\HttpFoundation\Response
            {
                // /logout is a shared Fortify route reached from both the desktop site and
                // the mobile app tree, so there's no /app-prefixed path to key off of here —
                // the referring page is the only signal available at this point.
                $isMobile = Str::startsWith(FortifyServiceProvider::pathOf($request->headers->get('referer')), '/app');

                return Inertia::location($isMobile ? '/app/login' : '/login');
            }
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => Features::enabled(Features::registration()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render(
            $this->isMobileFlow($request) ? 'Mobile/VerifyEmail' : 'auth/VerifyEmail',
            ['status' => $request->session()->get('status')]
        ));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'turnstileSiteKey' => config('services.turnstile.site_key'),
        ]));

        Fortify::twoFactorChallengeView(fn (Request $request) => Inertia::render(
            $this->isMobileFlow($request) ? 'Mobile/TwoFactorChallenge' : 'auth/TwoFactorChallenge'
        ));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Whether the account-flow interstitial about to be rendered (email verification,
     * two-factor challenge) belongs to the mobile app rather than the desktop site. Both
     * screens are reached via Fortify's own shared routes (not under /app), so the request
     * path itself can't tell us — but the session's intended URL (set by the `verified`
     * middleware, or still pending from the original guest redirect into /app) still points
     * at the mobile page tree at this point, since redirect()->intended() hasn't consumed it
     * yet for an in-progress login/verification flow.
     */
    private function isMobileFlow(Request $request): bool
    {
        return Str::startsWith(self::pathOf($request->session()->get('url.intended')), '/app');
    }

    /**
     * The path component of a URL, or '' if it isn't a valid absolute/relative URL. Public
     * because the anonymous LogoutResponse class above (a separate class, despite being
     * defined inline) needs it too.
     */
    public static function pathOf(mixed $url): string
    {
        if (! is_string($url)) {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
