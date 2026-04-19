<?php

namespace App\Providers;

use App\Listeners\EnsureUserHasDefaultTeam;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Yethee\Tiktoken\EncoderProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Share a single EncoderProvider across the request lifecycle so
        // tiktoken's vocab file is only parsed once per process. The
        // provider caches encoders internally; wasting that across every
        // chat turn would be expensive.
        $this->app->singleton(EncoderProvider::class, fn () => new EncoderProvider);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ログイン時にデフォルトチームを作成
        Event::listen(
            Login::class,
            EnsureUserHasDefaultTeam::class,
        );
    }
}
