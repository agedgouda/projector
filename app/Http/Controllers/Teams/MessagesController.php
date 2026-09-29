<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Jobs\ReplyToTeamsConversation;
use App\Services\Teams\TeamsBotClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class MessagesController extends Controller
{
    /**
     * How long the spike's queued reply waits — long enough that it's unambiguously sent outside
     * the inbound request, the way a real task/report reply would be.
     */
    private const QUEUED_REPLY_DELAY_SECONDS = 30;

    /**
     * Teams' single messaging endpoint — every activity (messages, installs, and later message
     * actions) arrives here, where Slack splits them across events/commands/interactivity.
     * VerifyTeamsToken (applied at the route level) has already authenticated the request.
     *
     * For now this is the cross-tenant spike: any message gets an immediate reply plus a queued
     * one, both logged with the tenant they went to, so a single test message from a customer's
     * tenant shows whether a single-tenant bot can reply there at all.
     */
    public function handle(Request $request, TeamsBotClient $client): Response
    {
        $type = $request->input('type');
        $serviceUrl = $request->input('serviceUrl');
        $conversationId = $request->input('conversation.id');
        $tenantId = $request->input('conversation.tenantId') ?? $request->input('channelData.tenant.id');
        $tenantId = is_string($tenantId) ? $tenantId : null;

        if ($type === 'conversationUpdate') {
            Log::info('Teams conversationUpdate received', [
                'tenant_id' => $tenantId,
                'team_id' => $request->input('channelData.team.id'),
                'team_name' => $request->input('channelData.team.name'),
                'service_url' => $serviceUrl,
                'members_added' => $request->input('membersAdded'),
            ]);
        } elseif ($type === 'message' && is_string($serviceUrl) && is_string($conversationId)) {
            $this->replyImmediately($client, $serviceUrl, $conversationId, $tenantId);

            ReplyToTeamsConversation::dispatch($serviceUrl, $conversationId, '✅ Queued reply from Projector (sent '.self::QUEUED_REPLY_DELAY_SECONDS.'s later from a background job).', $tenantId)
                ->delay(now()->addSeconds(self::QUEUED_REPLY_DELAY_SECONDS));
        } else {
            Log::info('Received unhandled Teams activity', ['type' => $type]);
        }

        return response('', 200);
    }

    /**
     * Failures are logged rather than thrown — a non-2xx answer to the activity itself would make
     * the Bot Connector retry it, and the queued reply still needs to be attempted either way.
     */
    private function replyImmediately(TeamsBotClient $client, string $serviceUrl, string $conversationId, ?string $tenantId): void
    {
        $context = [
            'conversation_tenant_id' => $tenantId,
            'bot_home_tenant_id' => config('services.teams.tenant_id'),
            'service_url' => $serviceUrl,
        ];

        try {
            $client->sendToConversation($serviceUrl, $conversationId, '👋 Projector received your message.');
            Log::info('Teams immediate reply delivered', $context);
        } catch (Throwable $e) {
            Log::error('Teams immediate reply failed: '.$e->getMessage(), $context);
        }
    }
}
