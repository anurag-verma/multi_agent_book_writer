<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * The AI pipeline binds its interfaces in App\AI\Providers\AIServiceProvider,
     * which is registered in bootstrap/providers.php. Keeping that wiring in one
     * place means a reviewer can find every AI binding in a single file.
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
        //
    }
}