<?php

namespace App\Http\Middleware;

use App\Models\Document;
use App\Models\Project;
use App\Services\Logging\RecordSaveLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs every attempt to save a change to a document — on arrival and again with the outcome —
 * including the ones Laravel never logs by itself: validation failures (422), permission errors
 * (403/404), and a response that redirects somewhere unexpected. See RecordSaveLogger.
 */
class LogRecordSaves
{
    /**
     * The routes that change a document — the only ones this middleware logs.
     *
     * @var list<string>
     */
    private const ROUTES = [
        'projects.documents.updateAttributes',
        'projects.documents.updateCategories',
        'projects.documents.move',
        'projects.documents.update',
    ];

    public function __construct(private RecordSaveLogger $log) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->route()?->getName(), self::ROUTES, true)) {
            return $next($request);
        }

        $started = microtime(true);
        $route = $request->route();
        $project = $route?->parameter('project');
        $document = $route?->parameter('document');

        $context = [
            'route' => $route?->getName(),
            'method' => $request->method(),
            'project_id' => $project instanceof Project ? $project->id : $project,
            'document_id' => $document instanceof Document ? $document->id : $document,
        ];

        $this->log->info('received', $context + [
            'input' => $this->log->describeInput($request->except(['_token', '_method'])),
            'expects_json' => $request->expectsJson(),
            'inertia' => $request->header('X-Inertia') !== null,
            'client_sent_at' => $request->header('X-Client-Sent-At'),
            'client_queued_saves' => $request->header('X-Client-Queued-Saves'),
            'referer' => $request->header('Referer'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->log->error('exception', $context + [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'duration_ms' => $this->elapsed($started),
            ]);

            throw $e;
        }

        $status = $response->getStatusCode();
        $outcome = $context + [
            'status' => $status,
            'duration_ms' => $this->elapsed($started),
            // A short fingerprint, not the id itself: enough to tell whether two saves share a
            // session (concurrent requests racing on one session's flash data is a suspect).
            'session' => $request->hasSession() ? substr(hash('sha256', $request->session()->getId()), 0, 8) : null,
        ];

        if ($response->isRedirection()) {
            $outcome['redirect_to'] = $response->headers->get('Location');
        }

        if ($response instanceof JsonResponse && $status >= 400) {
            $body = $response->getData(true);
            $outcome['response'] = is_array($body) ? array_intersect_key($body, ['message' => 1, 'errors' => 1]) : null;
        }

        // 2xx (and the redirect an Inertia save answers with) is the normal outcome; anything
        // else is a save that did not happen and is exactly what needs to be findable later.
        if ($status >= 400) {
            $this->log->warning('failed', $outcome);
        } else {
            $this->log->info('responded', $outcome);
        }

        return $response;
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
