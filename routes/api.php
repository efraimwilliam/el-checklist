<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChecklistController as AppChecklistController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistItemController;
use App\Http\Controllers\ChecklistPhotoController;
use App\Http\Middleware\EnsureActiveToken;
use Illuminate\Support\Facades\Route;

// Aplikasi Android (token Sanctum). Simpan isian / catatan / foto memakai controller web yang sama.
Route::prefix('v1/app')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', EnsureActiveToken::class, 'throttle:120,1'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/checklists', [AppChecklistController::class, 'index']);
        Route::get('/checklists/{checklist}', [AppChecklistController::class, 'show']);
        Route::patch('/checklists/{checklist}', [ChecklistController::class, 'meta']);
        Route::patch('/checklists/{checklist}/items/{item}', [ChecklistItemController::class, 'update'])->scopeBindings();
        Route::post('/checklists/{checklist}/items/{item}/photos', [ChecklistPhotoController::class, 'store'])->scopeBindings()->middleware('throttle:60,1');
        Route::get('/checklists/{checklist}/photos/{photo}', [ChecklistPhotoController::class, 'show']);
        Route::delete('/checklists/{checklist}/photos/{photo}', [ChecklistPhotoController::class, 'destroy']);
    });
});
