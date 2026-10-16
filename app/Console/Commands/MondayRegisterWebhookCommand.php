<?php

namespace App\Console\Commands;

use App\Services\MondayApiClient;
use Illuminate\Console\Command;
use Throwable;

class MondayRegisterWebhookCommand extends Command
{
    protected $signature = 'monday:register-webhook {board_id : monday.com board id} {--url= : full callback URL (defaults to APP_URL/webhooks/monday/{secret})}';

    protected $description = 'Register a create-item webhook on a monday.com board using this app\'s secret callback URL';

    public function handle(MondayApiClient $client): int
    {
        if (! $client->configured()) {
            $this->error('monday.com API token is not configured (MONDAY_API_TOKEN).');

            return self::FAILURE;
        }

        $secret = (string) config('monday.webhook_secret', '');
        if (preg_match('/^[A-Za-z0-9_-]{32,}$/', $secret) !== 1) {
            $this->error('Set MONDAY_WEBHOOK_SECRET to at least 32 random URL-safe characters before registering a webhook.');

            return self::FAILURE;
        }

        $boardId = (int) $this->argument('board_id');
        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/webhooks/monday/'.rawurlencode($secret);
        if (! str_ends_with((string) parse_url($url, PHP_URL_PATH), '/webhooks/monday/'.$secret)) {
            $this->error('The callback URL must end with /webhooks/monday/{MONDAY_WEBHOOK_SECRET}.');

            return self::FAILURE;
        }

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

        $this->info("Webhook #{$webhookId} registered on board {$boardId}.");

        return self::SUCCESS;
    }
}
