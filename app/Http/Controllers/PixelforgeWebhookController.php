<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    /**
     * Pixelforge delivers events at least once, in any order. The event id's primary key turns a repeat into a no-op,
     * even when two copies arrive at the same moment, and syncFromPixelforge() never moves a generation backwards.
     * The work is a single quick transaction, so it runs inline, well inside Pixelforge's 10 second timeout.
     */
    public function __invoke(Request $request): Response
    {
        $event = $request->json()->all();

        DB::transaction(function () use ($event) {
            $isNew = DB::table('pixelforge_events')->insertOrIgnore([
                'id' => $event['id'],
                'type' => $event['type'],
                'received_at' => now(),
            ]) === 1;

            if (! $isNew || ! str_starts_with($event['type'], 'generation.')) {
                return;
            }

            $remote = $event['data']['object'];
            $generation = Generation::findForPixelforge($remote);

            if ($generation === null) {
                Log::warning('Pixelforge webhook for an unknown generation.', ['event' => $event['id'], 'generation' => $remote['id']]);

                return;
            }

            $generation->syncFromPixelforge($remote);
        });

        return response()->noContent();
    }
}
