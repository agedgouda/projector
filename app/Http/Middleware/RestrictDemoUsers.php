<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo accounts get the whole app except what reaches outside it (email, invitations,
 * integrations), changes the account itself, stores uploaded files, or runs AI outside the
 * limited per-sandbox budget.
 */
class RestrictDemoUsers
{
    public const MESSAGE = "That isn't available in the demo. Start a free trial to use it.";

    /**
     * Route names demo accounts can't use, as Str::is() patterns.
     *
     * @var list<string>
     */
    private const BLOCKED_ROUTES = [
        'organizations.store',
        'organizations.update',
        'organizations.destroy',
        'organizations.invite',
        'organizations.invitations.*',
        'organizations.users.*',
        'organizations.logo.*',
        'organizations.pdf-branding.*',
        'organizations.slack.*',
        'organizations.dropbox.*',
        'organizations.documents.*',
        'organizations.import-*',
        'organizations.dismiss-recording',
        'organization.setup.*',
        'mobile.*',
        'clients.logo.*',
        'projects.logo.*',
        'projects.browser-recordings.*',
        'projects.content-uploads.*',
        'projects.transcripts.import-*',
        'projects.task-lists.*',
        'projects.import-transformations.*',
        'projects.generate',
        'projects.evaluate-description',
        'transformation-library.generate-prompts',
        'import.*',
        'integrations.*',
        'profile.update',
        'profile.destroy',
        'user-password.update',
        'two-factor.*',
        'password.confirm.store',
        'verification.send',
        'bug-reports.store',
        'users.*',
        'roles.*',
        'storage.local.upload',
    ];

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $routeName = $request->route()?->getName();

        if (! $user instanceof User || ! $user->isDemo() || ! is_string($routeName) || ! Str::is(self::BLOCKED_ROUTES, $routeName)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return back()->with('error', self::MESSAGE);
    }
}
