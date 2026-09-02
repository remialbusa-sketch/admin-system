<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin GraphQL client for the monday.com API v2. No official PHP SDK exists,
 * so this stays dependency-free (Laravel's Http client only) and is easily
 * testable with Http::fake().
 *
 * Transport contract (see docs/monday-integration-and-cpanel-deployment.md §A2/A7):
 *   - Authorization header is the raw token (monday v2 does NOT use a Bearer prefix).
 *   - API-Version pinned so behaviour doesn't drift with releases.
 *   - Retry/backoff on 429 (respects Retry-After) and 5xx.
 *   - 401 is FATAL (dead/expired token) — throws so callers stop the schedule.
 */
class MondayApiClient
{
    public const PAGE_SIZE = 500;

    public function __construct(
        protected ?string $token = null,
        protected string $apiUrl = 'https://api.monday.com/v2',
        protected string $apiVersion = '2023-10',
        protected int $timeout = 30,
        protected int $retries = 3,
    ) {
        $this->token = $token ?? config('monday.token');
        $this->apiUrl = config('monday.api_url', $this->apiUrl);
        $this->timeout = (int) config('monday.timeout', $this->timeout);
        $this->retries = (int) config('monday.retries', $this->retries);
    }

    public function configured(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    /**
     * Read a board's column definitions (id, title, type) — powers
     * monday:inspect-board and the fast auto-map when connecting a board.
     *
     * @return array<int, array{id: string, title: string, type: string}>
     */
    public function boardColumns(int $boardId): array
    {
        $query = <<<'GQL'
            query BoardColumns($boardId: ID!) {
              boards(ids: [$boardId]) {
                id
                columns { id title type settings_str }
              }
            }
        GQL;

        return $this->firstResponsePath($query, ['boardId' => $boardId], ['data', 'boards', 0, 'columns']) ?? [];
    }

    /**
     * Fetch one page of item ids (no column values — the lightweight query the
     * delta scan uses to discover new items).
     *
     * @return array{cursor: ?string, items: array<int, array{id: string, name: string, created_at: ?string, updated_at: ?string}>}
     */
    public function itemsPage(int $boardId, ?string $cursor = null): array
    {
        $query = <<<'GQL'
            query BoardItems($boardId: ID!, $cursor: String) {
              boards(ids: [$boardId]) {
                id
                items_page(limit: 500, cursor: $cursor) {
                  cursor
                  items { id name created_at updated_at }
                }
              }
            }
        GQL;

        $data = $this->query($query, ['boardId' => $boardId, 'cursor' => $cursor]);
        $page = $data['data']['boards'][0]['items_page'] ?? null;

        return [
            'cursor' => $page['cursor'] ?? null,
            'items' => $page['items'] ?? [],
        ];
    }

    /**
     * Refetch full items (id, name, timestamps, column values with typed
     * fragments incl. mirror resolution) by id — used on the delta-scan path
     * and by the webhook job for a single newly created item.
     *
     * @param  array<int, int|string>  $itemIds
     * @return array<int, array<string, mixed>>
     */
    public function items(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $query = <<<'GQL'
            query Items($ids: [ID!]!) {
              items(ids: $ids) {
                id name created_at updated_at
                column_values {
                  id text type
                  ... on StatusValue   { label index }
                  ... on DateValue     { date }
                  ... on NumbersValue  { number }
                  ... on DropdownValue { text }
                  ... on MirrorValue   { display_value }
                }
              }
            }
        GQL;

        $data = $this->query($query, ['ids' => $itemIds]);

        return $data['data']['items'] ?? [];
    }

    /**
     * Register a create-item webhook on a board (M-W deployment step).
     * Monday POSTs to the given URL within ~5s of an item being created.
     * Returns the monday webhook id on success.
     *
     * @return int|null webhook id, or null if monday returned no webhook
     */
    public function createWebhook(int $boardId, string $url, string $event = 'create_item'): ?int
    {
        $query = <<<'GQL'
            mutation CreateWebhook($boardId: ID!, $url: String!, $event: WebhookEventType!) {
              create_webhook(board_id: $boardId, url: $url, event: $event) {
                id
                board_id
              }
            }
        GQL;

        return $this->firstResponsePath($query, ['boardId' => $boardId, 'url' => $url, 'event' => $event], ['data', 'create_webhook', 'id']);
    }

    /**
     * Fetch the webhooks registered on a board (useful to avoid duplicate
     * registrations / confirm one exists at deploy time).
     *
     * @return array<int, array{id: string, board_id: string, event: ?string}>
     */
    public function boardWebhooks(int $boardId): array
    {
        $query = <<<'GQL'
            query BoardWebhooks($boardId: [ID!]) {
              boards(ids: $boardId) {
                id
                webhooks { id event board_id }
              }
            }
        GQL;

        return $this->firstResponsePath($query, ['boardId' => $boardId], ['data', 'boards', 0, 'webhooks']) ?? [];
    }

    /**
     * Run a GraphQL query with retry/backoff and unified error handling.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> full decoded response body
     */
    public function query(string $query, array $variables = []): array
    {
        if (! $this->configured()) {
            throw new MondayApiException('monday.com API token is not configured (MONDAY_API_TOKEN).');
        }

        $request = fn (): Response => Http::timeout($this->timeout)
            ->withHeaders([
                'Authorization' => $this->token,
                'API-Version' => $this->apiVersion,
                'Content-Type' => 'application/json',
            ])
            ->post($this->apiUrl, ['query' => $query, 'variables' => $variables]);

        $attempt = 0;
        $response = null;

        while (true) {
            try {
                $response = $request();
            } catch (ConnectionException $exception) {
                $attempt++;
                if ($attempt > $this->retries) {
                    throw new MondayApiException('monday.com connection failed: '.$exception->getMessage());
                }
                usleep(500000 * $attempt);

                continue;
            }

            $status = $response->status();

            if ($status === 401) {
                throw new MondayApiException('monday.com rejected the API token (HTTP 401). Check MONDAY_API_TOKEN.');
            }

            if ($status === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?: 30);
                $attempt++;
                if ($attempt > $this->retries) {
                    throw new MondayRateLimitException("monday.com rate limit hit (HTTP 429). Retry after {$retryAfter}s.", $retryAfter);
                }
                sleep(min($retryAfter, 60) * $attempt);

                continue;
            }

            if ($status >= 500) {
                $attempt++;
                if ($attempt > $this->retries) {
                    throw new MondayApiException("monday.com server error (HTTP {$status}).");
                }
                usleep(500000 * $attempt);

                continue;
            }

            break;
        }

        $body = $response->json();

        if (! is_array($body) || isset($body['errors'])) {
            $message = is_array($body)
                ? ($body['errors'][0]['message'] ?? 'Unknown GraphQL error')
                : 'Invalid response body';

            throw new MondayApiException('monday.com GraphQL error: '.$message);
        }

        return $body;
    }

    /**
     * @param  array<int, mixed>  $path
     */
    private function firstResponsePath(string $query, array $variables, array $path): mixed
    {
        $body = $this->query($query, $variables);
        $cursor = $body;

        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}

class MondayApiException extends RuntimeException {}

class MondayRateLimitException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 30)
    {
        parent::__construct($message);
    }
}
