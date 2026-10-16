<?php

namespace App\Http\Controllers;

use App\Jobs\MondaySyncItemJob;
use App\Models\MondayWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M-W: inbound endpoint for monday.com create-item webhooks.
 *
 * - Registration challenge: monday POSTs `{challenge}`; we echo it so the
 *   webhook can be registered on the server.
 * - Delivery: the payload carries boardId, pulseId (item id) and a
 *   challenge/triggerUuid. We dedup on triggerUuid (unique), stamp the event,
 *   return 200 IMMEDIATELY (monday's 5s budget), then dispatch a queue job that
 *   refetches the full item and maps it through the same pipeline as the delta
 *   scan.
 *
 * Personal-token board webhooks do not guarantee a signed JWT. Registration
 * uses a random secret in the callback path; every request, including the
 * challenge, must provide it. Dedup handles repeated deliveries.
 */
class MondayWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret): JsonResponse
    {
        $expected = (string) config('monday.webhook_secret', '');
        if (preg_match('/^[A-Za-z0-9_-]{32,}$/', $expected) !== 1 || ! hash_equals($expected, $secret)) {
            return response()->json(['error' => 'Unauthorized webhook'], 403);
        }

        // Registration challenge handshake.
        if ($request->has('challenge')) {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        $payload = $request->json()->all();
        $event = $payload['event'] ?? [];
        $triggerUuid = (string) ($event['triggerUuid'] ?? $payload['triggerUuid'] ?? '');
        $itemId = (string) ($event['pulseId'] ?? $payload['pulseId'] ?? '');
        $boardId = $event['boardId'] ?? $payload['boardId'] ?? null;

        // Dedup: if this delivery was already processed, drop it.
        if ($triggerUuid !== '' && MondayWebhookEvent::query()->where('trigger_uuid', $triggerUuid)->exists()) {
            return response()->json(['duplicate' => true], 200);
        }

        if ($triggerUuid !== '') {
            MondayWebhookEvent::create([
                'trigger_uuid' => $triggerUuid,
                'event_type' => (string) ($event['type'] ?? ''),
                'item_id' => $itemId,
                'payload' => $payload,
            ]);
        }

        // Only act on item creations (the connected path); other events are
        // acknowledged but ignored.
        $eventType = (string) ($event['type'] ?? '');
        $isCreate = str_contains($eventType, 'create');

        if ($itemId !== '' && $boardId !== null && $isCreate) {
            MondaySyncItemJob::dispatch($itemId, $boardId, $triggerUuid);
        }

        return response()->json(['ok' => true], 200);
    }
}
