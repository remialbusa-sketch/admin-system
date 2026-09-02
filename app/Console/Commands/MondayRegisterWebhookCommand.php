<?php

namespace App\Console\Commands;

use App\Services\MondayApiClient;
use Illuminate\Console\Command;
use Throwable;

class MondayRegisterWebhookCommand extends Command
{
    protected $signature = 'monday:register-webhook {board_id : monday.com board id} {--url= : callback URL (defaults to APP_URL/webhooks/monday)}';

    protected $description = 'Register a create-item webhook on a monday.com board, pointing at this app\'s /webhooks/monday endpoint (M-W deployment step)';

    public function handle(MondayApiClient $client): int
    {
        if (! $client->configured()) {
            $this->error('monday.com API token is not configured (MONDAY_API_TOKEN).');

            return self::FAILURE;
        }

        $boardId = (int) $this->argument('board_id');
        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/webhooks/monday';

        try {
            $webhookId = $client->createWebhook($boardId, $url, 'create_item');
        } catch (Throwable $exception) {
            $this->error('Registration failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($webhookId === null) {
            $this->warn('monday did not return a webhook id (it may already exist, or the board/token lacks permission).');

            return self::FAILURE;
        }

        $this->info("Webhook #{$webhookId} registered on board {$boardId} -> {$url}");

        return self::SUCCESS;
    }
}
