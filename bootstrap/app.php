<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetOrganizationContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Spatie\Permission\Exceptions\UnauthorizedException;

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
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'client.access' => \App\Http\Middleware\EnsureUserCanAccessClient::class,
            'org-role' => \App\Http\Middleware\CheckOrgRole::class,
            'slack.signature' => \App\Http\Middleware\VerifySlackSignature::class,
        ]);

        // Slack's webhooks (Events API, slash commands, interactivity) are verified via their
        // own HMAC signature (VerifySlackSignature) instead of a CSRF token — Slack's servers
        // can't carry one.
        $middleware->validateCsrfTokens(except: [
            'slack/events',
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
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
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
        });

        // 2. Handle Laravel Policy/Gate failures (AccessDenied)
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, $request) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
        });

        // 3. Catch CSRF/Session timeouts
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($e->getStatusCode() === 419) {
                return redirect()->route('login')->with([
                    'message' => 'Your session expired. Please log in again.',
                ]);
            }
        });

    })->create();
