<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CreditPurchaseController extends Controller
{
    /** What users can buy, in dollars (shared with the frontend as creditAmounts). */
    public const array AMOUNTS = [5, 10, 20];

    /**
     * A fake purchase: no payment is taken, the credits are just added.
     */
    public function store(Request $request): RedirectResponse
    {
        $dollars = (int) $request->validate([
            'amount' => ['required', 'integer', Rule::in(self::AMOUNTS)],
        ])['amount'];

        $request->user()->creditPurchases()->create(['amount' => $dollars * 1_000_000_000]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added $:amount to your credits.', ['amount' => $dollars])]);

        return back();
    }
}
