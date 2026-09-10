<?php

declare(strict_types=1);

namespace SimoneBianco\ActivityMatrix;

use Illuminate\Support\ServiceProvider;

final class ActivityMatrixServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
