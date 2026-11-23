<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Agency\Ai\AiLeadExtractor;
use App\Services\Agency\Ai\NullAiLeadExtractor;
use App\Services\BookingExport\BookingExportService;
use App\Services\BookingExport\CsvBookingExport;
use App\Services\BookingExport\NullBookingExport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Pluggable AI step for agency parsing. Add a provider by implementing AiLeadExtractor and mapping it here.
        $this->app->bind(AiLeadExtractor::class, function () {
            $provider = config('leads.ai.provider') ?: 'none';

            if ($provider !== 'none' && config('leads.mode') === 'live') {
                Log::warning("AI_PROVIDER={$provider} has no implementation yet; skipping the AI step.");
            }

            return new NullAiLeadExtractor;
        });

        // POS / Semnox hand-off. Add a driver by implementing BookingExportService and mapping it here.
        $this->app->bind(BookingExportService::class, fn () => match (config('leads.booking_export.driver')) {
            'csv' => new CsvBookingExport,
            default => new NullBookingExport,
        });
    }

    public function boot(): void
    {
        // Numeric ids only: on PostgreSQL a non-numeric id would be a query error (500) instead of a 404.
        foreach (['lead', 'user', 'branch', 'message', 'booking', 'id', 'link'] as $param) {
            Route::pattern($param, '[0-9]+');
        }

        // Areas / packages / add-ons: admin (any outlet) or outlet manager (own outlet; CatalogController scopes it).
        Gate::define('manage-catalog', fn (User $user) => $user->canManageCatalog());

        // Public QR form + website API: per IP, 5/min and 30/hour (honeypot hits count too).
        RateLimiter::for('public-enquiry', fn (Request $request) => [
            Limit::perMinute(5)->by('pe-min:'.$request->ip()),
            Limit::perHour(30)->by('pe-hour:'.$request->ip()),
        ]);

        // Production is always served over HTTPS (Render terminates TLS at its proxy).
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
