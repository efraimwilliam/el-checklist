<?php

use App\Http\Controllers\Admin\AccessController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Auth\AttendanceLoginController;
use App\Http\Controllers\BulkAssignController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistItemController;
use App\Http\Controllers\ChecklistPhotoController;
use App\Http\Controllers\FillController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\TeamPositionController;
use App\Http\Controllers\TeamTaskController;
use App\Http\Middleware\EnsureActiveSession;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AttendanceLoginController::class, 'show'])->name('login');
    Route::post('/login', [AttendanceLoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware(['auth', EnsureActiveSession::class])->group(function () {
    Route::post('/logout', [AttendanceLoginController::class, 'destroy'])->name('logout');
    Route::get('/', HomeController::class)->name('home');

    Route::get('/checklist', [FillController::class, 'today'])->name('checklists.today');
    Route::get('/isi', [FillController::class, 'create'])->name('fill.create');
    Route::post('/isi/mulai', [FillController::class, 'start'])->name('fill.start');
    Route::post('/isi/semua', [BulkAssignController::class, 'store'])->name('fill.assign-all');
    Route::put('/isi/otomatis/{department}', [BulkAssignController::class, 'auto'])->name('fill.auto-assign');
    Route::get('/checklists', [ChecklistController::class, 'index'])->name('checklists.index');
    Route::get('/checklists/{checklist}', [ChecklistController::class, 'show'])->name('checklists.show');
    Route::get('/checklists/{checklist}/isi', [ChecklistController::class, 'fill'])->name('checklists.fill');
    Route::patch('/checklists/{checklist}', [ChecklistController::class, 'meta'])->name('checklists.meta');
    Route::patch('/checklists/{checklist}/items/{item}', [ChecklistItemController::class, 'update'])->scopeBindings()->name('checklists.items.update');
    Route::post('/checklists/{checklist}/items/{item}/photos', [ChecklistPhotoController::class, 'store'])->scopeBindings()->middleware('throttle:60,1')->name('checklists.photos.store');
    Route::get('/checklists/{checklist}/photos/{photo}', [ChecklistPhotoController::class, 'show'])->name('checklists.photos.show');
    Route::delete('/checklists/{checklist}/photos/{photo}', [ChecklistPhotoController::class, 'destroy'])->name('checklists.photos.destroy');
    Route::post('/checklists/{checklist}/rest-done', [ChecklistItemController::class, 'restDone'])->name('checklists.rest-done');
    Route::post('/checklists/{checklist}/clear', [ChecklistItemController::class, 'clear'])->name('checklists.clear');
    Route::post('/checklists/{checklist}/review', [ChecklistController::class, 'review'])->name('checklists.review');
    Route::delete('/checklists/{checklist}', [ChecklistController::class, 'destroy'])->name('checklists.destroy');

    Route::get('/tim', [TeamTaskController::class, 'index'])->name('team.index');
    Route::get('/tim/jabatan', [TeamPositionController::class, 'edit'])->name('team.positions.edit');
    Route::put('/tim/jabatan', [TeamPositionController::class, 'update'])->name('team.positions.update');
    Route::get('/tim/{employee}/tugas', [TeamTaskController::class, 'edit'])->name('team.tasks.edit');
    Route::put('/tim/{employee}/tugas', [TeamTaskController::class, 'update'])->name('team.tasks.update');
    Route::delete('/tim/{employee}/tugas', [TeamTaskController::class, 'reset'])->name('team.tasks.reset');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/akses', [AccessController::class, 'index'])->name('access.index');
        Route::get('/akses/{employee}', [AccessController::class, 'edit'])->name('access.edit');
        Route::put('/akses/{employee}', [AccessController::class, 'update'])->name('access.update');
        Route::post('/sinkron', [AccessController::class, 'sync'])->name('sync');
        Route::get('/pengaturan', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/pengaturan', [SettingsController::class, 'update'])->name('settings.update');

        Route::get('/tugas', [TaskController::class, 'index'])->name('tasks.index');
        Route::post('/tugas/jabatan', [TaskController::class, 'storePosition'])->name('positions.store');
        Route::get('/tugas/jabatan/{position}', [TaskController::class, 'show'])->name('tasks.show');
        Route::put('/tugas/jabatan/{position}', [TaskController::class, 'updatePosition'])->name('positions.update');
        Route::post('/tugas/jabatan/{position}/tugas', [TaskController::class, 'storeTask'])->name('tasks.store');
        Route::put('/tugas/{task}', [TaskController::class, 'updateTask'])->name('tasks.update');
        Route::delete('/tugas/{task}', [TaskController::class, 'destroyTask'])->name('tasks.destroy');
        Route::post('/tugas/{task}/geser', [TaskController::class, 'moveTask'])->name('tasks.move');
    });
});
