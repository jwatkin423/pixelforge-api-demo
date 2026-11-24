<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    config()->set('services.dokploy', [
        'url' => 'https://dokploy.test/',
        'api_key' => 'dk_test_key',
        'compose_id' => 'cmp_test123',
    ]);
});

/**
 * Fake Git output for a working copy with the given state.
 */
function fakeGitState(string $uncommitted = '', string $unpushedCount = '0'): void
{
    Process::fake([
        'git status --porcelain' => Process::result($uncommitted),
        'git rev-list --count @{upstream}..HEAD' => Process::result($unpushedCount),
    ]);
}

test('a clean, pushed branch triggers a compose deployment on Dokploy', function () {
    fakeGitState();
    Http::fake(['dokploy.test/api/compose.deploy' => Http::response(['success' => true])]);

    $this->artisan('dokploy:deploy')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://dokploy.test/api/compose.deploy'
        && $request->header('x-api-key') === ['dk_test_key']
        && $request->data() === ['composeId' => 'cmp_test123']);
});

test('missing Dokploy settings fail without calling the API', function () {
    config()->set('services.dokploy.api_key', null);
    fakeGitState();

    $this->artisan('dokploy:deploy')
        ->expectsOutputToContain('Set DOKPLOY_URL, DOKPLOY_API_KEY and DOKPLOY_COMPOSE_ID')
        ->assertFailed();

    Http::assertNothingSent();
});

test('uncommitted changes need confirmation before deploying', function () {
    fakeGitState(uncommitted: " M app/Models/User.php\n");

    $this->artisan('dokploy:deploy')
        ->expectsConfirmation('Deploy anyway?', 'no')
        ->assertFailed();

    Http::assertNothingSent();
});

test('unpushed commits can be deployed anyway with --force', function () {
    fakeGitState(unpushedCount: "2\n");
    Http::fake(['dokploy.test/api/compose.deploy' => Http::response(['success' => true])]);

    $this->artisan('dokploy:deploy', ['--force' => true])->assertSuccessful();

    Http::assertSentCount(1);
});

test('a rejected deployment reports the error and fails', function () {
    fakeGitState();
    Http::fake(['dokploy.test/api/compose.deploy' => Http::response(['message' => 'Unauthorized'], 401)]);

    $this->artisan('dokploy:deploy')
        ->expectsOutputToContain('HTTP 401')
        ->assertFailed();
});
