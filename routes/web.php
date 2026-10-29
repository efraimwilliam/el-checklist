<?php

use App\Http\Controllers\Admin\AccessController;
use App\Http\Controllers\Auth\AttendanceLoginController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\HomeController;
use App\Http\Middleware\EnsureActiveSession;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AttendanceLoginController::class, 'show'])->name('login');
    Route::post('/login', [AttendanceLoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware(['auth', EnsureActiveSession::class])->group(function () {
    Route::post('/logout', [AttendanceLoginController::class, 'destroy'])->name('logout');
    Route::get('/', HomeController::class)->name('home');

    Route::get('/checklist', [ChecklistController::class, 'today'])->name('checklists.today');
    Route::get('/checklists', [ChecklistController::class, 'index'])->name('checklists.index');
    Route::get('/checklists/{checklist}', [ChecklistController::class, 'show'])->name('checklists.show');
    Route::put('/checklists/{checklist}', [ChecklistController::class, 'update'])->name('checklists.update');
    Route::post('/checklists/{checklist}/review', [ChecklistController::class, 'review'])->name('checklists.review');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/akses', [AccessController::class, 'index'])->name('access.index');
        Route::get('/akses/{employee}', [AccessController::class, 'edit'])->name('access.edit');
        Route::put('/akses/{employee}', [AccessController::class, 'update'])->name('access.update');
        Route::post('/sinkron', [AccessController::class, 'sync'])->name('sync');
    });
});
