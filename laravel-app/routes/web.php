<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DecisionSupportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectAccessController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDocumentController;
use App\Http\Controllers\ProjectEvaluationController;
use App\Http\Controllers\ProjectNotificationController;
use App\Http\Controllers\ProjectSearchController;
use App\Http\Controllers\ProjectWorkflowController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/ai-search', ProjectSearchController::class)->name('projects.search');
    Route::get('/decision-support', DecisionSupportController::class)->name('dss.index');
    Route::get('/notifications', [ProjectNotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [ProjectNotificationController::class, 'read'])->name('notifications.read');
    Route::get('/projects/{project}/access', [ProjectAccessController::class, 'edit'])->name('projects.access.edit');
    Route::put('/projects/{project}/access', [ProjectAccessController::class, 'update'])->name('projects.access.update');
    Route::resource('projects', ProjectController::class);
    Route::post('/projects/{project}/submit', [ProjectWorkflowController::class, 'submit'])->name('projects.workflow.submit');
    Route::post('/projects/{project}/screen', [ProjectWorkflowController::class, 'screen'])->name('projects.workflow.screen');
    Route::post('/projects/{project}/decide', [ProjectWorkflowController::class, 'decide'])->name('projects.workflow.decide');
    Route::post('/projects/{project}/complete', [ProjectWorkflowController::class, 'complete'])->name('projects.workflow.complete');
    Route::post('/projects/{project}/documents', [ProjectDocumentController::class, 'store'])->name('projects.documents.store');
    Route::get('/projects/{project}/evaluate', [ProjectEvaluationController::class, 'edit'])->name('projects.evaluations.edit');
    Route::post('/projects/{project}/evaluations', [ProjectEvaluationController::class, 'store'])->name('projects.evaluations.store');
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::get('/users/{managedUser}/edit', [UserManagementController::class, 'edit'])->name('users.edit');
    Route::put('/users/{managedUser}', [UserManagementController::class, 'update'])->name('users.update');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

Route::view('/app/{path?}', 'spa')
    ->where('path', '.*')
    ->name('spa');
