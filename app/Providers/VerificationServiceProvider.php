<?php

namespace App\Providers;

use App\Services\Extraction\AwsTextractProvider;
use App\Services\Extraction\DocumentClassifier;
use App\Services\Extraction\FieldExtractor;
use App\Services\Extraction\ProviderManager;
use App\Services\Extraction\TesseractOcrProvider;
use Illuminate\Support\ServiceProvider;

class VerificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderManager::class, function ($app) {
            $manager = new ProviderManager();
            $manager->register($app->make(TesseractOcrProvider::class));
            $manager->register($app->make(AwsTextractProvider::class));
            return $manager;
        });
    }

    public function boot(): void
    {
        //
    }
}
