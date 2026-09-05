<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class EventsController extends Controller
{
    /**
     * Handles Slack's Events API callbacks. `VerifySlackSignature` (applied at the route level)
     * has already authenticated the request before this runs.
     *
     * Slack requires this endpoint to answer the one-time `url_verification` handshake before it
     * will let an admin save a Request URL in the app config — that's all this does for now.
     * Real event types (message posted, file shared) get subscribed to and handled once the
     * features that need them are built.
     */
    public function handle(Request $request): JsonResponse|Response
    {
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        Log::info('Received unhandled Slack event', ['type' => $request->input('type'), 'event_type' => $request->input('event.type')]);

        return response()->noContent();
    }
}
