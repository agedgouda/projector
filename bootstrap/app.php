<?php

use App\Http\Middleware\CheckOrgRole;
use App\Http\Middleware\EnsureUserCanAccessClient;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogRecordSaves;
use App\Http\Middleware\RecordDemoActivity;
use App\Http\Middleware\RecordReferralVisit;
use App\Http\Middleware\RestrictDemoUsers;
use App\Http\Middleware\SetOrganizationContext;
use App\Http\Middleware\VerifyDropboxSignature;
use App\Http\Middleware\VerifyResendSignature;
use App\Http\Middleware\VerifySlackSignature;
use App\Http\Middleware\VerifyTeamsToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        // Unauthenticated hits on the mobile page tree land on the mobile app's own login
        // screen, not the desktop one — everything else keeps the normal behavior.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('app', 'app/*')
            ? route('mobile.login')
            : route('login', ['expired' => 1])
        );

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'client.access' => EnsureUserCanAccessClient::class,
            'org-role' => CheckOrgRole::class,
            'slack.signature' => VerifySlackSignature::class,
            'teams.token' => VerifyTeamsToken::class,
            'dropbox.signature' => VerifyDropboxSignature::class,
            'resend.signature' => VerifyResendSignature::class,
        ]);

        // Slack's/Dropbox's/Resend's webhooks are verified via their own HMAC signature
        // (VerifySlackSignature/VerifyDropboxSignature/VerifyResendSignature), and Teams' via a Bot Framework JWT
        // (VerifyTeamsToken), instead of a CSRF token — their servers
        // can't carry one. client-logs/stale-asset is exempted for a different reason: it's
        // reported by a stale tab whose own CSRF token may itself be stale.
        $middleware->validateCsrfTokens(except: [
            'slack/events',
            'slack/commands',
            'slack/interactivity',
            'dropbox/events',
            'resend/webhook',
            'teams/messages',
            'client-logs/stale-asset',
            'client-logs/record-save',
        ]);

        // First in the web stack, not on the routes themselves: a 404 from route-model binding or a
        // 419 from an expired session happens before any route-level middleware would run, and
        // those are exactly the failed saves that otherwise leave no trace. It only acts on the
        // document-save routes (see LogRecordSaves::ROUTES).
        $middleware->web(prepend: [
            LogRecordSaves::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            RestrictDemoUsers::class,
            RecordReferralVisit::class,
            RecordDemoActivity::class,
            SetOrganizationContext::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Policies rely on getPermissionsTeamId() to know the active org — this middleware
        // resolves it the same way the web group does (project/client route param first,
        // falling back to the user's own organization; there's no cookie for API clients).
        $middleware->api(append: [
            SetOrganizationContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 1. Handle Spatie Role/Permission failures
        $exceptions->render(function (UnauthorizedException $e, $request) {
            throw new NotFoundHttpException;
        });

        // 2. Handle Laravel Policy/Gate failures (AccessDenied)
        $exceptions->render(function (AccessDeniedHttpException $e, $request) {
            throw new NotFoundHttpException;
        });

        // 3. Catch CSRF/Session timeouts. Flashed under 'status' (not e.g. 'message') because
        // that's the key auth/Login.vue actually reads (see FortifyServiceProvider's
        // loginView) — flashing the wrong key silently dropped this message entirely, so a
        // stale-token login attempt just looked like the page blanking out for no reason.
        $exceptions->render(function (HttpException $e, $request) {
            if ($e->getStatusCode() === 419) {
                return redirect()->route('login')->with([
                    'status' => 'Your session expired. Please log in again.',
                ]);
            }
        });

    })->create();
