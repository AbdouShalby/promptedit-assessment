<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Support\Nano;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per UTC day and per user: what users were charged, what Pixelforge charged
 * us, and the profit. Each day is compared with GET /v1/usage, which is
 * Pixelforge's own record of what this API key was billed.
 */
class ReportController extends Controller
{
    private const MAX_RANGE_DAYS = 92;   // the limit of GET /v1/usage

    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        $input = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $end = Carbon::parse($input['end_date'] ?? now()->toDateString(), 'UTC');
        $start = Carbon::parse($input['start_date'] ?? $end->copy()->subDays(29)->toDateString(), 'UTC');

        if ($start->gt($end) || $start->diffInDays($end) >= self::MAX_RANGE_DAYS) {
            return $this->render($start, $end, days: [], total: null, error: 'Choose a range of at most '.self::MAX_RANGE_DAYS.' days, ending on or after its start.');
        }

        $rows = $this->rowsPerDayAndUser($start, $end);
        [$usageByDay, $usageError] = $this->providerUsage($pixelforge, $start, $end);

        // Every day we billed something, plus every day Pixelforge billed something
        // (a day that exists only on their side is exactly what this report must expose).
        $dates = $rows->keys()
            ->merge(collect($usageByDay ?? [])->filter(fn (int $nano) => $nano !== 0)->keys())
            ->unique()->sortDesc()->values();

        $days = $dates->map(fn (int|string $date) => $this->presentDay((string) $date, $rows->get($date, collect()), $usageByDay))->all();

        $allRows = $rows->flatten(1);

        return $this->render($start, $end, $days, total: [
            'generations' => $allRows->sum('generations'),
            'charged' => Nano::toDecimal($allRows->sum('charged_nano')),
            'provider_cost' => Nano::toDecimal($allRows->sum('provider_cost_nano')),
            'profit' => Nano::toDecimal($allRows->sum('charged_nano') - $allRows->sum('provider_cost_nano')),
            'usage_cost' => $usageByDay === null ? null : Nano::toDecimal(array_sum($usageByDay)),
            'matches_usage' => $usageByDay === null ? null : array_sum($usageByDay) === $allRows->sum('provider_cost_nano'),
        ], error: $usageError);
    }

    /**
     * Grouped by billing_date: the UTC day the generation completed, which is
     * the day Pixelforge bills it on. Only succeeded generations have one.
     *
     * @return Collection<array-key, Collection<int, array{date: string, user: string, generations: int, charged_nano: int, provider_cost_nano: int}>> keyed by date
     */
    private function rowsPerDayAndUser(Carbon $start, Carbon $end): Collection
    {
        $users = User::pluck('email', 'id');

        return Generation::query()
            ->whereBetween('billing_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('billing_date', 'user_id')
            ->orderBy('user_id')
            ->selectRaw('billing_date, user_id, COUNT(*) AS generations, SUM(charge_nano) AS charged_nano, SUM(provider_cost_nano) AS provider_cost_nano')
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'date' => Carbon::parse($row->billing_date)->toDateString(),
                'user' => (string) ($users[$row->user_id] ?? 'user #'.$row->user_id),
                'generations' => (int) $row->generations,
                // SUM over BIGINT comes back from Postgres as a numeric string: cast, never float.
                'charged_nano' => (int) $row->charged_nano,
                'provider_cost_nano' => (int) $row->provider_cost_nano,
            ])
            ->groupBy('date');
    }

    /**
     * @return array{0: array<string, int>|null, 1: string|null} [cost in nanodollars per date, error message]
     */
    private function providerUsage(PixelforgeClient $pixelforge, Carbon $start, Carbon $end): array
    {
        try {
            $usage = $pixelforge->usage($start->toDateString(), $end->toDateString());
        } catch (PixelforgeException $e) {
            return [null, "Could not load Pixelforge usage ({$e->errorCode}), so provider costs are not verified on this page."];
        }

        $byDay = [];

        foreach ($usage['data'] ?? [] as $day) {
            $byDay[(string) $day['date']] = Nano::fromDecimal((string) $day['cost_usd']);
        }

        return [$byDay, null];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, int>|null  $usageByDay
     * @return array<string, mixed>
     */
    private function presentDay(string $date, Collection $rows, ?array $usageByDay): array
    {
        $charged = $rows->sum('charged_nano');
        $providerCost = $rows->sum('provider_cost_nano');
        $usageCost = $usageByDay === null ? null : ($usageByDay[$date] ?? 0);

        return [
            'date' => $date,
            'rows' => $rows->map(fn (array $row) => [
                'user' => $row['user'],
                'generations' => $row['generations'],
                'charged' => Nano::toDecimal($row['charged_nano']),
                'provider_cost' => Nano::toDecimal($row['provider_cost_nano']),
                'profit' => Nano::toDecimal($row['charged_nano'] - $row['provider_cost_nano']),
            ])->values()->all(),
            'generations' => $rows->sum('generations'),
            'charged' => Nano::toDecimal($charged),
            'provider_cost' => Nano::toDecimal($providerCost),
            'profit' => Nano::toDecimal($charged - $providerCost),
            'usage_cost' => $usageCost === null ? null : Nano::toDecimal($usageCost),
            'matches_usage' => $usageCost === null ? null : $usageCost === $providerCost,
            // Shown, never hidden: ours minus theirs.
            'difference' => $usageCost === null || $usageCost === $providerCost ? null : Nano::toDecimal($providerCost - $usageCost),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @param  array<string, mixed>|null  $total
     */
    private function render(Carbon $start, Carbon $end, array $days, ?array $total, ?string $error): Response
    {
        return Inertia::render('admin/Report', [
            'filters' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'days' => $days,
            'total' => $total,
            'error' => $error,
        ]);
    }
}
