<?php

use App\Http\Controllers\PlanController;
use App\Http\Controllers\ChecklistTaskController;
use App\Http\Controllers\TaskCatalogImportController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AccessController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Auth\AttendanceLoginController;
use App\Http\Controllers\BulkAssignController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistItemController;
use App\Http\Controllers\ChecklistPhotoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FillController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TeamPositionController;
use App\Http\Controllers\TeamTaskController;
use App\Http\Middleware\EnsureActiveSession;
use Illuminate\Support\Facades\Route;

Route::get('/bahasa/{locale}', [LocaleController::class, 'update'])->where('locale', 'id|en')->name('locale.update');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AttendanceLoginController::class, 'show'])->name('login');
    Route::post('/login', [AttendanceLoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware(['auth', EnsureActiveSession::class])->group(function () {
    Route::post('/logout', [AttendanceLoginController::class, 'destroy'])->name('logout');
    Route::get('/', HomeController::class)->name('home');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/laporan', [ReportController::class, 'index'])->name('laporan.index');
    Route::get('/laporan/ekspor.xlsx', [ReportController::class, 'export'])->name('laporan.export');
    Route::post('/laporan/catatan', [ReportController::class, 'storeNote'])->name('laporan.notes.store');
    Route::delete('/laporan/catatan/{note}', [ReportController::class, 'destroyNote'])->name('laporan.notes.destroy');

    Route::get('/checklist', [FillController::class, 'today'])->name('checklists.today');
    Route::get('/isi', [FillController::class, 'create'])->name('fill.create');
    Route::get('/checklist/atur', [FillController::class, 'assign'])->name('fill.assign');
    Route::delete('/checklist/atur/rencana', [PlanController::class, 'destroyDay'])->name('fill.plan.destroy-day');
    Route::post('/checklist/atur/mulai', [PlanController::class, 'start'])->name('fill.plan.start');
    Route::delete('/checklists/{checklist}/rencana', [PlanController::class, 'destroy'])->name('checklists.plan.destroy');
    Route::post('/isi/mulai', [FillController::class, 'start'])->name('fill.start');
    Route::post('/isi/semua', [BulkAssignController::class, 'store'])->name('fill.assign-all');
    Route::put('/isi/otomatis/{department}', [BulkAssignController::class, 'auto'])->name('fill.auto-assign');
    Route::get('/checklists', [ChecklistController::class, 'index'])->name('checklists.index');
    Route::get('/checklists/ekspor.xlsx', [ChecklistController::class, 'export'])->name('checklists.export');
    Route::get('/checklists/{checklist}', [ChecklistController::class, 'show'])->name('checklists.show');
    Route::get('/checklists/{checklist}/isi', [ChecklistController::class, 'fill'])->name('checklists.fill');
    Route::get('/checklists/{checklist}/tugas', [ChecklistTaskController::class, 'edit'])->name('checklists.tasks.edit');
    Route::put('/checklists/{checklist}/tugas', [ChecklistTaskController::class, 'update'])->name('checklists.tasks.update');
    Route::patch('/checklists/{checklist}', [ChecklistController::class, 'meta'])->name('checklists.meta');
    Route::patch('/checklists/{checklist}/items/{item}', [ChecklistItemController::class, 'update'])->scopeBindings()->name('checklists.items.update');
    Route::post('/checklists/{checklist}/items/{item}/photos', [ChecklistPhotoController::class, 'store'])->scopeBindings()->middleware('throttle:60,1')->name('checklists.photos.store');
    Route::get('/checklists/{checklist}/photos/{photo}', [ChecklistPhotoController::class, 'show'])->name('checklists.photos.show');
    Route::delete('/checklists/{checklist}/photos/{photo}', [ChecklistPhotoController::class, 'destroy'])->name('checklists.photos.destroy');
    Route::post('/checklists/{checklist}/review', [ChecklistController::class, 'review'])->name('checklists.review');
    Route::post('/checklists/{checklist}/batal-review', [ChecklistController::class, 'unreview'])->name('checklists.unreview');
    Route::delete('/checklists/{checklist}', [ChecklistController::class, 'destroy'])->name('checklists.destroy');

    Route::get('/profil', [AccountController::class, 'editOwn'])->name('profile.edit');
    Route::put('/profil', [AccountController::class, 'updateOwn'])->name('profile.update');
    Route::get('/tim', [TeamTaskController::class, 'index'])->name('team.index');
    Route::get('/tim/daftar', [TeamTaskController::class, 'catalog'])->name('team.catalog');

    Route::get('/tim/daftar/ekspor.xlsx', [TaskCatalogImportController::class, 'export'])->name('team.catalog.export');
    Route::get('/tim/daftar/impor', [TaskCatalogImportController::class, 'create'])->name('team.catalog.import');
    Route::post('/tim/daftar/impor', [TaskCatalogImportController::class, 'preview'])->name('team.catalog.import.preview');
    Route::post('/tim/daftar/impor/simpan', [TaskCatalogImportController::class, 'apply'])->name('team.catalog.import.apply');
    Route::post('/tim/daftar/impor/batal', [TaskCatalogImportController::class, 'cancel'])->name('team.catalog.import.cancel');    Route::get('/tim/jabatan', [TeamPositionController::class, 'edit'])->name('team.positions.edit');
    Route::put('/tim/jabatan', [TeamPositionController::class, 'update'])->name('team.positions.update');
    Route::get('/tim/{employee}/tugas', [TeamTaskController::class, 'edit'])->name('team.tasks.edit');
    Route::put('/tim/{employee}/tugas', [TeamTaskController::class, 'update'])->name('team.tasks.update');
    Route::get('/tim/{employee}/akun', [AccountController::class, 'editOther'])->middleware('can:manage-tasks')->name('team.account.edit');
    Route::put('/tim/{employee}/akun', [AccountController::class, 'updateOther'])->middleware('can:manage-tasks')->name('team.account.update');
    Route::delete('/tim/{employee}/tugas', [TeamTaskController::class, 'reset'])->name('team.tasks.reset');
    Route::post('/tim/daftar/tugas', [TeamTaskController::class, 'storeTask'])->name('team.tasks.store');
    Route::put('/tim/daftar/tugas/{task}', [TeamTaskController::class, 'updateCatalogTask'])->name('team.catalog.tasks.update');
    Route::delete('/tim/daftar/tugas/{task}', [TeamTaskController::class, 'destroyCatalogTask'])->name('team.catalog.tasks.destroy');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/akses', [AccessController::class, 'index'])->name('access.index');
        Route::get('/akses/{employee}', [AccessController::class, 'edit'])->name('access.edit');
        Route::put('/akses/{employee}', [AccessController::class, 'update'])->name('access.update');
        Route::post('/sinkron', [AccessController::class, 'sync'])->name('sync');
        Route::get('/pengaturan', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::get('/log', [AuditLogController::class, 'index'])->name('logs.index');
        Route::put('/pengaturan', [SettingsController::class, 'update'])->name('settings.update');
        Route::put('/pengaturan/aturan', [SettingsController::class, 'updateRules'])->name('settings.rules.update');

        Route::get('/tugas', [TaskController::class, 'index'])->name('tasks.index');
        Route::post('/tugas/jabatan', [TaskController::class, 'storePosition'])->name('positions.store');
        Route::get('/tugas/jabatan/{position}', [TaskController::class, 'show'])->name('tasks.show');
        Route::put('/tugas/jabatan/{position}', [TaskController::class, 'updatePosition'])->name('positions.update');
        Route::delete('/tugas/jabatan/{position}', [TaskController::class, 'destroyPosition'])->name('positions.destroy');
        Route::post('/tugas/jabatan/{position}/tugas', [TaskController::class, 'storeTask'])->name('tasks.store');
        Route::put('/tugas/{task}', [TaskController::class, 'updateTask'])->name('tasks.update');
        Route::delete('/tugas/{task}', [TaskController::class, 'destroyTask'])->name('tasks.destroy');
        Route::post('/tugas/{task}/geser', [TaskController::class, 'moveTask'])->name('tasks.move');
    });
});
