<?php

use App\Http\Controllers\Api\ProjectSummaryController;
use App\Http\Controllers\Api\V2\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V2\Auth\MeController;
use App\Http\Controllers\Api\V2\BudgetManagementController;
use App\Http\Controllers\Api\V2\ConfirmDocumentImportController;
use App\Http\Controllers\Api\V2\DashboardController;
use App\Http\Controllers\Api\V2\DocumentImportController;
use App\Http\Controllers\Api\V2\DocumentImportOptionsController;
use App\Http\Controllers\Api\V2\DocumentVersionDownloadController;
use App\Http\Controllers\Api\V2\EvaluationFrameworkController;
use App\Http\Controllers\Api\V2\EvaluationOptionsController;
use App\Http\Controllers\Api\V2\EvaluationProjectController;
use App\Http\Controllers\Api\V2\ImportExtractionCallbackController;
use App\Http\Controllers\Api\V2\ImportPreviewRevisionController;
use App\Http\Controllers\Api\V2\ProjectController as V2ProjectController;
use App\Http\Controllers\Api\V2\ProjectEvaluationController;
use App\Http\Controllers\Api\V2\ProjectOptionsController;
use App\Http\Middleware\VerifyImportCallbackSignature;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/projects/{project}/ai-summary', [ProjectSummaryController::class, 'store']);

Route::post(
    '/v2/import-extraction-runs/{run}/callback',
    ImportExtractionCallbackController::class,
)->withoutMiddleware([SubstituteBindings::class, ThrottleRequests::class.':api'])
    ->middleware(['throttle:import-callback', VerifyImportCallbackSignature::class])
    ->whereUuid('run')
    ->name('api.v2.import-extraction-runs.callback');

Route::prefix('v2')
    ->as('api.v2.')
    ->middleware(EnsureFrontendRequestsAreStateful::class)
    ->group(function () {
        Route::post('/auth/login', [AuthenticatedSessionController::class, 'store'])
            ->name('auth.login');

        Route::middleware(['auth:web', 'active-user'])->group(function () {
            Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])
                ->name('auth.logout');
            Route::get('/me', MeController::class)->name('me');
            Route::get('/dashboard', DashboardController::class)->name('dashboard');
            Route::get('/imports/options', DocumentImportOptionsController::class)
                ->name('imports.options');
            Route::get('/imports', [DocumentImportController::class, 'index'])
                ->name('imports.index');
            Route::post('/imports', [DocumentImportController::class, 'store'])
                ->name('imports.store');
            Route::get('/imports/{documentImport}', [DocumentImportController::class, 'show'])
                ->name('imports.show');
            Route::get('/imports/{documentImport}/original', [DocumentImportController::class, 'original'])
                ->name('imports.original');
            Route::post('/imports/{documentImport}/retry', [DocumentImportController::class, 'retry'])
                ->name('imports.retry');
            Route::get('/imports/{documentImport}/preview-revisions', [ImportPreviewRevisionController::class, 'index'])
                ->name('imports.preview-revisions.index');
            Route::post('/imports/{documentImport}/preview-revisions', [ImportPreviewRevisionController::class, 'store'])
                ->name('imports.preview-revisions.store');
            Route::post('/imports/{documentImport}/confirm', ConfirmDocumentImportController::class)
                ->name('imports.confirm');
            Route::get('/budget-management', [BudgetManagementController::class, 'index'])
                ->name('budget-management.index');
            Route::put('/fiscal-years/{fiscalYear}/school-budget', [BudgetManagementController::class, 'updateSchoolBudget'])
                ->name('fiscal-years.school-budget.update');
            Route::put('/fiscal-years/{fiscalYear}/department-budgets/{department}', [BudgetManagementController::class, 'updateDepartmentBudget'])
                ->name('fiscal-years.department-budgets.update');
            Route::get('/project-options', ProjectOptionsController::class)
                ->name('project-options');
            Route::get(
                '/projects/{project}/documents/{projectDocument}/versions/{documentVersion:public_id}/download',
                DocumentVersionDownloadController::class,
            )->withoutScopedBindings()
                ->whereUuid('documentVersion')
                ->name('projects.documents.versions.download');
            Route::get('/evaluation-options', EvaluationOptionsController::class)
                ->name('evaluation-options');
            Route::get('/evaluation-projects', [EvaluationProjectController::class, 'index'])
                ->name('evaluation-projects.index');
            Route::get('/projects/{project}/evaluations', [ProjectEvaluationController::class, 'index'])
                ->name('projects.evaluations.index');
            Route::post('/projects/{project}/evaluations', [ProjectEvaluationController::class, 'store'])
                ->name('projects.evaluations.store');
            Route::get('/project-evaluations/{evaluation}', [ProjectEvaluationController::class, 'show'])
                ->name('project-evaluations.show');
            Route::put('/project-evaluations/{evaluation}', [ProjectEvaluationController::class, 'update'])
                ->name('project-evaluations.update');
            Route::post('/project-evaluations/{evaluation}/finalize', [ProjectEvaluationController::class, 'finalize'])
                ->name('project-evaluations.finalize');
            Route::get('/evaluation-frameworks', [EvaluationFrameworkController::class, 'index'])
                ->name('evaluation-frameworks.index');
            Route::post('/evaluation-frameworks', [EvaluationFrameworkController::class, 'store'])
                ->name('evaluation-frameworks.store');
            Route::get('/evaluation-frameworks/{framework}', [EvaluationFrameworkController::class, 'show'])
                ->name('evaluation-frameworks.show');
            Route::put('/evaluation-frameworks/{framework}', [EvaluationFrameworkController::class, 'update'])
                ->name('evaluation-frameworks.update');
            Route::post('/evaluation-frameworks/{framework}/versions', [EvaluationFrameworkController::class, 'storeVersion'])
                ->name('evaluation-frameworks.versions.store');
            Route::post('/evaluation-frameworks/{framework}/activate', [EvaluationFrameworkController::class, 'activate'])
                ->name('evaluation-frameworks.activate');
            Route::post('/evaluation-frameworks/{framework}/deactivate', [EvaluationFrameworkController::class, 'deactivate'])
                ->name('evaluation-frameworks.deactivate');
            Route::apiResource('projects', V2ProjectController::class)
                ->only(['index', 'store', 'show', 'update', 'destroy']);
        });
    });
