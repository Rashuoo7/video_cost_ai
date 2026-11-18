<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('users:make-admin {email}', function (string $email) {
    User::where('email', $email)->firstOrFail()->forceFill(['is_admin' => true])->save();

    $this->info("{$email} can now see the spend report.");
})->purpose('Let a user see the operators’ spend report');

Artisan::command('generations:sync', function (PixelforgeClient $pixelforge) {
    // Webhook delivery isn't guaranteed, so poll anything that has sat unfinished for a few minutes. A batch of 20 a
    // minute stays well inside Pixelforge's 60 requests a minute, which every environment's key shares.
    Generation::whereNotNull('pixelforge_id')
        ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
        ->where('updated_at', '<', now()->subMinutes(3))
        ->oldest('updated_at')
        ->limit(20)
        ->get()
        ->each(fn (Generation $generation) => rescue(
            fn () => $generation->syncFromPixelforge($pixelforge->generation((string) $generation->pixelforge_id)),
        ));
})->purpose('Poll Pixelforge for generations whose webhooks never arrived');

Schedule::command('generations:sync')->everyMinute()->withoutOverlapping();
