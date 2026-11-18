<?php

namespace App\Http\Controllers;

use App\Reports\SpendReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    /**
     * The operators' spend report, for the last 30 days unless a range is given.
     */
    public function __invoke(Request $request, SpendReport $report): Response
    {
        // Malformed dates fall back to the defaults instead of redirecting with errors.
        $dates = Validator::make($request->query(), [
            'from' => ['date_format:Y-m-d'],
            'to' => ['date_format:Y-m-d'],
        ])->valid();

        $today = CarbonImmutable::today();
        $to = isset($dates['to']) ? CarbonImmutable::parse($dates['to'])->min($today) : $today;
        $from = isset($dates['from']) ? CarbonImmutable::parse($dates['from']) : $to->subDays(29);
        // Pixelforge's usage report covers at most 92 days.
        $from = $from->max($to->subDays(91))->min($to);

        return Inertia::render('admin/Report', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            ...$report->build($from, $to),
        ]);
    }
}
