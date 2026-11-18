<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->travelTo('2026-11-17 12:00:00');
});

function admin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('is only for operators', function () {
    $this->get(route('admin.report'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
});

it('reports spend per day of completion and per user, checked against Pixelforge’s usage', function () {
    $ada = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
    $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    Generation::factory()->for($ada)->succeeded(100_000_000, '2026-11-16 09:00:00')->create();
    Generation::factory()->for($ada)->succeeded(200_000_000, '2026-11-16 18:00:00')->create();
    // Created on the 16th but finished on the 17th, so Pixelforge bills it on the 17th.
    Generation::factory()->for($ada)->succeeded(8_000_000, '2026-11-17 00:00:30')->create(['created_at' => '2026-11-16 23:59:50']);
    Generation::factory()->for($bob)->succeeded(40_000_000, '2026-11-17 10:00:00')->create();
    // Neither failed nor unfinished generations are billed.
    Generation::factory()->for($bob)->create(['status' => GenerationStatus::Failed, 'cost' => 0, 'provider_cost' => 0, 'completed_at' => '2026-11-17 10:00:00']);
    Generation::factory()->for($bob)->create(['status' => GenerationStatus::Processing]);

    Http::fake(['*/usage*' => [
        'object' => 'usage',
        'data' => [
            ['date' => '2026-11-15', 'generations' => 1, 'cost_usd' => '0.010000000'], // one we never recorded
            ['date' => '2026-11-16', 'generations' => 2, 'cost_usd' => '0.300000000'],
            ['date' => '2026-11-17', 'generations' => 2, 'cost_usd' => '0.050000000'],
        ],
    ]]);

    $this->actingAs(admin())
        ->get(route('admin.report'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Report')
            ->where('from', '2026-10-19')
            ->where('to', '2026-11-17')
            ->where('days', [
                ['date' => '2026-11-17', 'images' => 2, 'charged' => 60_000_000, 'provider_cost' => 48_000_000, 'profit' => 12_000_000, 'pixelforge_cost' => 50_000_000],
                ['date' => '2026-11-16', 'images' => 2, 'charged' => 375_000_000, 'provider_cost' => 300_000_000, 'profit' => 75_000_000, 'pixelforge_cost' => 300_000_000],
                ['date' => '2026-11-15', 'images' => 0, 'charged' => 0, 'provider_cost' => 0, 'profit' => 0, 'pixelforge_cost' => 10_000_000],
            ])
            ->where('users', [
                ['date' => '2026-11-17', 'user' => ['name' => 'Bob', 'email' => 'bob@example.com'], 'images' => 1, 'charged' => 50_000_000, 'provider_cost' => 40_000_000, 'profit' => 10_000_000],
                ['date' => '2026-11-17', 'user' => ['name' => 'Ada', 'email' => 'ada@example.com'], 'images' => 1, 'charged' => 10_000_000, 'provider_cost' => 8_000_000, 'profit' => 2_000_000],
                ['date' => '2026-11-16', 'user' => ['name' => 'Ada', 'email' => 'ada@example.com'], 'images' => 2, 'charged' => 375_000_000, 'provider_cost' => 300_000_000, 'profit' => 75_000_000],
            ])
            ->where('totals', ['images' => 4, 'charged' => 435_000_000, 'provider_cost' => 348_000_000, 'profit' => 87_000_000, 'pixelforge_cost' => 360_000_000]));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'start_date=2026-10-19&end_date=2026-11-17'));
});

it('still reports our own figures when Pixelforge can’t be reached', function () {
    Generation::factory()->succeeded(100_000_000, '2026-11-17 09:00:00')->create();
    Http::fake(['*/usage*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Overloaded.']], 503)]);

    $this->actingAs(admin())
        ->get(route('admin.report'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('days.0.provider_cost', 100_000_000)
            ->where('days.0.pixelforge_cost', null)
            ->where('totals.pixelforge_cost', null));
});

it('counts exactly the generations completed within the range', function () {
    Http::fake(['*/usage*' => ['object' => 'usage', 'data' => []]]);
    foreach (['2026-11-01 23:59:59', '2026-11-02 00:00:00', '2026-11-10 23:59:59', '2026-11-11 00:00:00'] as $completedAt) {
        Generation::factory()->succeeded(100_000_000, $completedAt)->create();
    }

    $this->actingAs(admin())
        ->get(route('admin.report', ['from' => '2026-11-02', 'to' => '2026-11-10']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totals.images', 2)
            ->where('days.0.date', '2026-11-10')
            ->where('days.1.date', '2026-11-02'));
});

it('keeps the date range within what Pixelforge can report', function () {
    Http::fake(['*/usage*' => ['object' => 'usage', 'data' => []]]);
    $range = fn (array $query) => $this->actingAs(admin())->get(route('admin.report', $query));

    // At most 92 days.
    $range(['from' => '2025-01-01', 'to' => '2026-11-10'])
        ->assertInertia(fn (AssertableInertia $page) => $page->where('from', '2026-08-11')->where('to', '2026-11-10'));
    // Nothing after today, so a future end date doesn't use up the window.
    $range(['from' => '2026-11-01', 'to' => '2026-12-31'])
        ->assertInertia(fn (AssertableInertia $page) => $page->where('from', '2026-11-01')->where('to', '2026-11-17'));
    // Malformed dates fall back to the last 30 days.
    $range(['from' => 'yesterday', 'to' => '2026-13-45'])
        ->assertInertia(fn (AssertableInertia $page) => $page->where('from', '2026-10-19')->where('to', '2026-11-17'));
});

it('turns a user into an operator', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('users:make-admin', ['email' => 'ops@example.com'])->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();
});
