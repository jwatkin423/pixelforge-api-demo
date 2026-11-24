<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationLifecycleService;
use App\Services\Pixelforge\WebhookSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives Pixelforge events. Delivery is at-least-once and unordered, so we:
 *  1. verify the signature against the raw body,
 *  2. drop events we've already recorded (unique event id),
 *  3. hand the generation snapshot to the lifecycle, which only ever moves forward.
 * Steps 2 and 3 share a transaction, so a crash mid-way lets Pixelforge's retry redo both.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignatureService $signature, GenerationLifecycleService $lifecycle): JsonResponse
    {
        if (! $signature->isValid($request->getContent(), $request->header('Pixelforge-Signature'))) {
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = json_decode($request->getContent(), true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response()->json(['error' => 'Malformed event.'], 400);
        }

        if ($event['type'] === 'ping') {
            return response()->json(['received' => true]);
        }

        /** @var array<string, mixed> $snapshot */
        $snapshot = (array) data_get($event, 'data.object', []);

        $outcome = DB::transaction(function () use ($event, $snapshot, $request, $lifecycle): string {
            $record = WebhookEvent::query()->createOrFirst(['event_id' => $event['id']], [
                'type' => $event['type'],
                'pixelforge_generation_id' => $snapshot['id'] ?? null,
                'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt') ?: null,
                'payload' => $event,
            ]);

            if (! $record->wasRecentlyCreated) {
                return 'duplicate';
            }

            $generation = $this->findGeneration($snapshot);

            if ($generation === null) {
                Log::warning('Pixelforge webhook for an unknown generation', ['event' => $event['id']]);

                return 'unknown_generation';
            }

            $lifecycle->applySnapshot($generation, $snapshot);

            return 'processed';
        });

        return response()->json(['received' => true, 'outcome' => $outcome]);
    }

    /**
     * Match by our own UUID from metadata first: the webhook can arrive before the create
     * response, i.e. before we know Pixelforge's id.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function findGeneration(array $snapshot): ?Generation
    {
        $uuid = data_get($snapshot, 'metadata.generation_uuid');

        if (is_string($uuid) && $uuid !== '') {
            return Generation::query()->where('uuid', $uuid)->first();
        }

        $pixelforgeId = $snapshot['id'] ?? null;

        return is_string($pixelforgeId) ? Generation::query()->where('pixelforge_id', $pixelforgeId)->first() : null;
    }
}
