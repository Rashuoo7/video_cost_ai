<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        Gate::define('viewSpendReport', fn (User $user) => $user->is_admin);
    }

    /**
     * Pixelforge's limits (60 requests a minute, 500 generations a day) are shared by every user, so cap each one.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('generations', fn (Request $request) => [
            Limit::perMinute(10)->by('minute:'.$request->user()?->id)->response(fn () => back()->withErrors([
                'prompt' => __('That’s a lot of images at once. Please wait a minute and try again.'),
            ])),
            Limit::perDay(50)->by('day:'.$request->user()?->id)->response(fn () => back()->withErrors([
                'prompt' => __('You’ve reached today’s limit of 50 images. Please try again tomorrow.'),
            ])),
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
