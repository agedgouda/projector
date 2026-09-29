<?php

namespace App\Jobs;

use App\Services\Teams\TeamsBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a message into a Teams conversation from a queued job, after the inbound activity's
 * request has long finished — the "proactive" send every async Teams reply (task created, import
 * result, report link) depends on. Its first use is the cross-tenant spike: whether a
 * single-tenant bot can do this into a customer's tenant at all.
 */
class ReplyToTeamsConversation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public string $serviceUrl,
        public string $conversationId,
        public string $text,
        public ?string $tenantId = null,
    ) {}

    public function handle(TeamsBotClient $client): void
    {
        $client->sendToConversation($this->serviceUrl, $this->conversationId, $this->text);

        Log::info('Teams queued reply delivered', $this->context());
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Teams queued reply failed: '.$exception->getMessage(), $this->context());
    }

    /**
     * @return array<string, string|null>
     */
    private function context(): array
    {
        $homeTenantId = config('services.teams.tenant_id');

        return [
            'conversation_tenant_id' => $this->tenantId,
            'bot_home_tenant_id' => is_string($homeTenantId) ? $homeTenantId : null,
            'service_url' => $this->serviceUrl,
        ];
    }
}
