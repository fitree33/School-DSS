<?php

use App\Http\Controllers\Api\ProjectSummaryController;
use App\Http\Controllers\Api\V2\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V2\Auth\MeController;
use App\Http\Controllers\Api\V2\ProjectController as V2ProjectController;
use App\Http\Controllers\Api\V2\ProjectOptionsController;
use Illuminate\Http\Request;
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
            Route::get('/project-options', ProjectOptionsController::class)
                ->name('project-options');
            Route::apiResource('projects', V2ProjectController::class)
                ->only(['index', 'store', 'show', 'update', 'destroy']);
        });
    });
