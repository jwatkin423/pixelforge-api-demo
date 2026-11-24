<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use App\Services\GenerationLifecycleService;
use App\Services\Pixelforge\PixelforgeClientService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Webhook delivery isn't guaranteed, so this polls generations that have gone quiet.
 */
#[Signature('pixelforge:sync {--stale=60 : Only poll generations not updated for this many seconds} {--limit=20 : Maximum generations to poll per run}')]
#[Description('Poll Pixelforge for in-flight generations whose webhooks may have been missed')]
class SyncActiveGenerations extends Command
{
    public function handle(PixelforgeClientService $pixelforge, GenerationLifecycleService $lifecycle): int
    {
        $generations = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<=', now()->subSeconds((int) $this->option('stale')))
            ->oldest('updated_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($generations as $generation) {
            try {
                $lifecycle->applySnapshot($generation, $pixelforge->getGeneration((string) $generation->pixelforge_id));
            } catch (PixelforgeApiException $exception) {
                if ($exception->errorCode === 'rate_limit_exceeded') {
                    $this->warn('Rate limited by Pixelforge, stopping early.');

                    break;
                }

                $this->error("{$generation->uuid}: {$exception->errorCode}");
            } catch (ConnectionException $exception) {
                $this->error("{$generation->uuid}: {$exception->getMessage()}");
            }
        }

        $this->info("Synced {$generations->count()} generation(s).");

        return self::SUCCESS;
    }
}
