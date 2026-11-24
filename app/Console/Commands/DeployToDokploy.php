<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Asks Dokploy to rebuild and redeploy the Compose app. Dokploy builds from the Git
 * repository, not this working copy, so uncommitted or unpushed work is called out first.
 */
#[Signature('dokploy:deploy {--force : Deploy even if local changes have not been pushed}')]
#[Description('Trigger a deployment of the Compose app on Dokploy')]
class DeployToDokploy extends Command
{
    public function handle(): int
    {
        $url = rtrim((string) config('services.dokploy.url'), '/');
        $apiKey = (string) config('services.dokploy.api_key');
        $composeId = (string) config('services.dokploy.compose_id');

        if ($url === '' || $apiKey === '' || $composeId === '') {
            $this->error('Set DOKPLOY_URL, DOKPLOY_API_KEY and DOKPLOY_COMPOSE_ID in .env first.');

            return self::FAILURE;
        }

        $warning = $this->undeployedWorkWarning();

        if ($warning !== null && ! $this->option('force')) {
            $this->warn($warning.' Dokploy deploys what is on the remote branch.');

            if (! $this->confirm('Deploy anyway?')) {
                $this->info('Deployment cancelled.');

                return self::FAILURE;
            }
        }

        try {
            $response = Http::baseUrl($url)
                ->withHeaders(['x-api-key' => $apiKey])
                ->acceptJson()
                ->timeout(30)
                ->post('/api/compose.deploy', ['composeId' => $composeId]);
        } catch (ConnectionException $exception) {
            $this->error("Could not reach Dokploy at {$url}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error("Dokploy rejected the deployment (HTTP {$response->status()}): {$response->body()}");

            return self::FAILURE;
        }

        $this->info('Deployment started. Follow its progress in the Deployments tab on Dokploy.');

        return self::SUCCESS;
    }

    private function undeployedWorkWarning(): ?string
    {
        $status = Process::run('git status --porcelain');

        if ($status->failed()) {
            return 'Could not read the Git status.';
        }

        if (trim($status->output()) !== '') {
            return 'You have uncommitted changes.';
        }

        $unpushed = Process::run('git rev-list --count @{upstream}..HEAD');

        if ($unpushed->failed()) {
            return 'This branch has no upstream on the remote.';
        }

        if ((int) trim($unpushed->output()) > 0) {
            return 'You have commits that are not pushed.';
        }

        return null;
    }
}
