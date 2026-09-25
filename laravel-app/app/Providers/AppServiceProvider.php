<?php

namespace App\Providers;

use App\Contracts\Imports\PdfTextExtractor;
use App\Contracts\Imports\ProcessRunner;
use App\Contracts\Imports\ProjectExtractionProvider;
use App\Contracts\Signatures\SignatureImageNormalizer;
use App\Services\Imports\N8nProjectExtractionProvider;
use App\Services\Imports\PopplerPdfTextExtractor;
use App\Services\Imports\SymfonyProcessRunner;
use App\Services\Signatures\GdSignatureImageNormalizer;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProcessRunner::class, SymfonyProcessRunner::class);
        $this->app->singleton(SignatureImageNormalizer::class, GdSignatureImageNormalizer::class);

        $this->app->singleton(PdfTextExtractor::class, function ($app): PdfTextExtractor {
            return new PopplerPdfTextExtractor(
                $app->make(ProcessRunner::class),
                (string) config('project_imports.pdf.pdfinfo_binary'),
                (string) config('project_imports.pdf.pdftotext_binary'),
                (float) config('project_imports.pdf.timeout_seconds'),
                (int) config('project_imports.pdf.max_pages'),
                (int) config('project_imports.pdf.max_text_bytes'),
                (bool) config('project_imports.pdf.reject_encrypted'),
            );
        });

        $this->app->singleton(ProjectExtractionProvider::class, function ($app): ProjectExtractionProvider {
            $driver = (string) config('project_imports.provider.driver', 'n8n');

            if ($driver !== 'n8n') {
                throw new InvalidArgumentException("Unsupported project import provider [{$driver}].");
            }

            $url = config('project_imports.provider.n8n.url');
            $token = config('project_imports.provider.n8n.token');
            $callbackUrl = config('project_imports.callback.url');

            return new N8nProjectExtractionProvider(
                $app->make(HttpFactory::class),
                is_string($url) ? $url : null,
                is_string($token) ? $token : null,
                is_string($callbackUrl) ? $callbackUrl : null,
                (float) config('project_imports.provider.n8n.timeout_seconds'),
                (float) config('project_imports.provider.n8n.connect_timeout_seconds'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.navigation', function ($view) {
            $view->with(
                'unreadNotificationCount',
                Auth::check()
                    ? Auth::user()->notifications()->whereNull('read_at')->count()
                    : 0
            );
        });
    }
}
