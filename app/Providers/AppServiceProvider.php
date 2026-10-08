<?php

namespace App\Providers;

use App\Support\ForumInventory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }

    public function register(): void
    {
        // one parse of the inventory per process, however many tools ask
        $this->app->singleton(ForumInventory::class);
    }
}
