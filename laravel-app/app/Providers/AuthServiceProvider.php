<?php

namespace App\Providers;

use App\Models\DocumentImport;
use App\Models\DocumentVersion;
use App\Models\EvaluationFramework;
use App\Models\Project;
use App\Models\ProjectEvaluation;
use App\Policies\DocumentImportPolicy;
use App\Policies\DocumentVersionPolicy;
use App\Policies\EvaluationFrameworkPolicy;
use App\Policies\ProjectEvaluationPolicy;
use App\Policies\ProjectPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        DocumentImport::class => DocumentImportPolicy::class,
        DocumentVersion::class => DocumentVersionPolicy::class,
        EvaluationFramework::class => EvaluationFrameworkPolicy::class,
        Project::class => ProjectPolicy::class,
        ProjectEvaluation::class => ProjectEvaluationPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        Gate::define('manageUsers', fn ($user) => $user->hasPermission('users.manage'));
        Gate::define('viewDss', fn ($user) => $user->hasPermission('projects.evaluate'));
    }
}
