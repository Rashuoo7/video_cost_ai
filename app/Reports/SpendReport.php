<?php

namespace App\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Pixelforge\Pricing;
use Carbon\CarbonImmutable;

/**
 * What users were charged, what Pixelforge charged us, and the profit, per UTC day and per user. A generation counts on
 * the day it completed, the day Pixelforge bills it on, so each day's provider cost can be checked against GET /v1/usage.
 * Money is in nanodollars.
 */
final class SpendReport
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    /**
     * @return array{
     *     days: list<array{date: string, images: int, charged: int, provider_cost: int, profit: int, pixelforge_cost: int|null}>,
     *     users: list<array{date: string, user: array{name: string, email: string}|null, images: int, charged: int, provider_cost: int, profit: int}>,
     *     totals: array{images: int, charged: int, provider_cost: int, profit: int, pixelforge_cost: int|null},
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Generation::query()
            ->where('status', GenerationStatus::Succeeded) // failed generations are free, and Pixelforge doesn't bill them
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('DATE(completed_at) as date, user_id, COUNT(*) as images, SUM(cost) as charged, SUM(provider_cost) as provider_cost')
            ->groupBy('date', 'user_id')
            ->toBase()
            ->get();

        $people = User::whereIn('id', $rows->pluck('user_id')->filter())->get(['id', 'name', 'email'])->keyBy('id');

        $users = $rows
            ->map(fn (object $row) => [
                'date' => (string) $row->date,
                'user' => ($person = $people->get($row->user_id)) ? ['name' => $person->name, 'email' => $person->email] : null,
                'images' => (int) $row->images,
                'charged' => (int) $row->charged,
                'provider_cost' => (int) $row->provider_cost,
                'profit' => (int) $row->charged - (int) $row->provider_cost,
            ])
            ->sortBy([['date', 'desc'], ['charged', 'desc']]);

        $pixelforge = $this->pixelforgeCosts($from, $to);

        // Days only Pixelforge has costs for are listed too: that's a generation we never recorded.
        $days = $users->pluck('date')
            ->merge(array_keys(array_filter($pixelforge ?? [])))
            ->unique()
            ->sortDesc()
            ->map(fn (string $date) => [
                'date' => $date,
                ...$this->sum($users->where('date', $date)),
                'pixelforge_cost' => $pixelforge === null ? null : ($pixelforge[$date] ?? 0),
            ]);

        return [
            'days' => array_values($days->all()),
            'users' => array_values($users->all()),
            'totals' => [
                ...$this->sum($days),
                'pixelforge_cost' => $pixelforge === null ? null : array_sum($pixelforge),
            ],
        ];
    }

    /**
     * @param  iterable<array{images: int, charged: int, provider_cost: int, profit: int}>  $rows
     * @return array{images: int, charged: int, provider_cost: int, profit: int}
     */
    private function sum(iterable $rows): array
    {
        $sum = ['images' => 0, 'charged' => 0, 'provider_cost' => 0, 'profit' => 0];

        foreach ($rows as $row) {
            foreach ($sum as $key => $value) {
                $sum[$key] = $value + $row[$key];
            }
        }

        return $sum;
    }

    /**
     * What Pixelforge says it billed per UTC day, in nanodollars, or null if it couldn't be asked.
     *
     * @return array<string, int>|null
     */
    private function pixelforgeCosts(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        try {
            $usage = $this->pixelforge->usage($from->toDateString(), $to->toDateString());
        } catch (PixelforgeException $e) {
            report($e);

            return null;
        }

        $costs = [];

        foreach ($usage['data'] as $day) {
            $costs[$day['date']] = Pricing::nanodollars($day['cost_usd']);
        }

        return $costs;
    }
}
