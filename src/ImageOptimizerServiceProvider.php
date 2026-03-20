<?php

namespace ImageOptimizer;

use Illuminate\Support\ServiceProvider;

class ImageOptimizerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/image-optimizer.php' => config_path('image-optimizer.php'),
        ], 'image-optimizer-config');
    }
}
