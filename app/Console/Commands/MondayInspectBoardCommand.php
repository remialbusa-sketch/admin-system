<?php

namespace App\Console\Commands;

use App\Services\MondayApiClient;
use Illuminate\Console\Command;
use Throwable;

class MondayInspectBoardCommand extends Command
{
    protected $signature = 'monday:inspect-board {board_id : monday.com board id}';

    protected $description = 'Inspect a monday.com board: columns (id/title/type) and item count, to ground field maps against reality';

    public function handle(MondayApiClient $client): int
    {
        if (! $client->configured()) {
            $this->error('monday.com API token is not configured (MONDAY_API_TOKEN).');

            return self::FAILURE;
        }

        $boardId = (int) $this->argument('board_id');

        try {
            $columns = $client->boardColumns($boardId);
            $page = $client->itemsPage($boardId);

            $this->info("Board {$boardId}: ".count($columns).' columns, '.count($page['items']).'+ items (first page).');
            $this->info('Columns (map by id, never by title):');

            $rows = array_map(fn (array $column): array => [
                $column['id'] ?? '',
                $column['title'] ?? '',
                $column['type'] ?? '',
            ], $columns);

            $this->table(['id', 'title', 'type'], $rows);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
