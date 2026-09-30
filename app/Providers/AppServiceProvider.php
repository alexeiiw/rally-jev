<?php

namespace App\Providers;

use App\Contracts\DecisionProvider;
use App\Services\JevService;
use App\Services\MockJevService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DecisionProvider::class, function (): DecisionProvider {
            return config('services.jev.driver', 'mock') === 'api'
                ? new JevService()
                : new MockJevService();
        });
    }
}
