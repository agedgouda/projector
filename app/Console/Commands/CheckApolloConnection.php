<?php

namespace App\Console\Commands;

use App\Services\Apollo\ApolloApiClient;
use Illuminate\Console\Command;
use Throwable;

class CheckApolloConnection extends Command
{
    protected $signature = 'apollo:check';

    protected $description = 'Check that APOLLO_API_KEY can reach the Apollo.io API';

    public function handle(ApolloApiClient $apollo): int
    {
        try {
            $health = $apollo->health();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $health['healthy'] || ! $health['is_logged_in']) {
            $this->error('Apollo answered, but the key is not working: healthy='.var_export($health['healthy'], true).', is_logged_in='.var_export($health['is_logged_in'], true).'.');

            return self::FAILURE;
        }

        $this->info('Connected to Apollo.io: the API key is valid.');

        return self::SUCCESS;
    }
}
