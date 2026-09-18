<?php

namespace App\Providers;

use App\Http\Middleware\EnsureMemberCanUseApp;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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
        /*
         * Livewire action requests go to /livewire/update and only re-run route
         * middleware registered as persistent. Without this, a member suspended
         * while a tab was open could keep swiping and messaging from it until
         * they reloaded the page.
         */
        Livewire::addPersistentMiddleware([
            EnsureMemberCanUseApp::class,
        ]);
    }
}
