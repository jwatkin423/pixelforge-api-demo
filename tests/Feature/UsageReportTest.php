<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('non-admins cannot see the usage report', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.usage'))
        ->assertForbidden();
});

test('the report totals each day and user and reconciles against Pixelforge usage', function () {
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/usage*' => Http::response([
        'object' => 'usage',
        'data' => [
            ['date' => '2026-11-19', 'generations' => 0, 'cost_usd' => '0.000000000'],
            ['date' => '2026-11-20', 'generations' => 2, 'cost_usd' => '0.351635000'],
        ],
        'total' => ['generations' => 2, 'cost_usd' => '0.351635000'],
    ])]);
    $customer = User::factory()->create();
    Generation::factory()->for($customer)->succeeded(305_510_000)->create(['completed_at' => '2026-11-20 10:00:00']);
    Generation::factory()->for($customer)->succeeded(46_125_000)->create(['completed_at' => '2026-11-20 23:59:00']);
    Generation::factory()->for($customer)->failed()->create(['completed_at' => '2026-11-20 12:00:00']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.usage', ['start' => '2026-11-19', 'end' => '2026-11-20']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/UsageReport')
            ->has('days', 2)
            ->where('days.0.date', '2026-11-20')
            ->where('days.0.generations', 2)
            ->where('days.0.provider_cost_nanos', 351_635_000)
            ->where('days.0.credit_spend_nanos', 381_887_500 + 57_656_250)
            ->where('days.0.profit_nanos', 381_887_500 + 57_656_250 - 351_635_000)
            ->where('days.0.reconciled', true)
            ->has('users', 1)
            ->where('users.0.user_email', $customer->email));

    Http::assertSent(fn ($request) => $request['start_date'] === '2026-11-19' && $request['end_date'] === '2026-11-20');
});
