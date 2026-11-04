<?php

use App\Http\Controllers\PlanController;
use App\Http\Controllers\ChecklistTaskController;
use App\Http\Controllers\ChronologyReportController;
use App\Http\Controllers\TaskCatalogImportController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AccessController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\FloorPlanController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Auth\AttendanceLoginController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\BulkAssignController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistItemController;
use App\Http\Controllers\ChecklistPhotoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FillController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoomIssueController;
use App\Http\Controllers\TeamPositionController;
use App\Http\Controllers\TeamTaskController;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\VerifySsoSession;
use Illuminate\Support\Facades\Route;

Route::get('/bahasa/{locale}', [LocaleController::class, 'update'])->where('locale', 'id|en')->name('locale.update');

// Portal SSO: login sekali untuk semua sistem (aktif bila SSO_CLIENT_ID diisi).
Route::get('/sso/login', [SsoController::class, 'redirect'])->middleware('throttle:30,1')->name('sso.login');
Route::get('/sso/callback', [SsoController::class, 'callback'])->middleware('throttle:30,1')->name('sso.callback');
Route::get('/sso/logout', [SsoController::class, 'logout'])->name('sso.logout');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AttendanceLoginController::class, 'show'])->name('login');
    Route::post('/login', [AttendanceLoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware(['auth', EnsureActiveSession::class, VerifySsoSession::class])->group(function () {
    Route::post('/logout', [AttendanceLoginController::class, 'destroy'])->name('logout');
    Route::get('/', HomeController::class)->name('home');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/chronology', [ChronologyReportController::class, 'index'])->name('chronology.index');
    Route::get('/chronology/buat', [ChronologyReportController::class, 'create'])->name('chronology.create');
    Route::post('/chronology', [ChronologyReportController::class, 'store'])->name('chronology.store');
    Route::post('/chronology/tempel', [ChronologyReportController::class, 'paste'])->name('chronology.paste');
    Route::get('/chronology/{report}', [ChronologyReportController::class, 'show'])->whereNumber('report')->name('chronology.show');
    Route::get('/chronology/{report}/ubah', [ChronologyReportController::class, 'edit'])->whereNumber('report')->name('chronology.edit');
    Route::put('/chronology/{report}', [ChronologyReportController::class, 'update'])->whereNumber('report')->name('chronology.update');
    Route::post('/chronology/{report}/final', [ChronologyReportController::class, 'finalize'])->whereNumber('report')->name('chronology.finalize');
    Route::post('/chronology/{report}/buka-lagi', [ChronologyReportController::class, 'reopen'])->whereNumber('report')->name('chronology.reopen');
    Route::delete('/chronology/{report}', [ChronologyReportController::class, 'destroy'])->whereNumber('report')->name('chronology.destroy');
    Route::get('/kamar/kendala', [RoomIssueController::class, 'index'])->name('kamar.issues.index');
    Route::get('/kamar/{room}', [RoomIssueController::class, 'room'])->whereNumber('room')->name('kamar.room');
    Route::get('/denah/{plan}/gambar', [FloorPlanController::class, 'image'])->name('denah.image');
    Route::get('/kamar/kendala/serupa', [RoomIssueController::class, 'duplicate'])->name('kamar.issues.duplicate');
    Route::post('/kamar/kendala', [RoomIssueController::class, 'store'])->name('kamar.issues.store');
    Route::get('/kamar/kendala/{issue}', [RoomIssueController::class, 'show'])->name('kamar.issues.show');
    Route::get('/kamar/kendala/{issue}/kronologi', [RoomIssueController::class, 'chronology'])->name('kamar.issues.chronology');
    Route::get('/kamar/kendala/{issue}/foto/{photo}', [RoomIssueController::class, 'photo'])->name('kamar.issues.photo');
    Route::post('/kamar/kendala/{issue}/status', [RoomIssueController::class, 'progress'])->name('kamar.issues.update');
    Route::post('/kamar/kendala/{issue}/ambil', [RoomIssueController::class, 'claim'])->name('kamar.issues.claim');
    Route::post('/kamar/kendala/{issue}/lepas', [RoomIssueController::class, 'release'])->name('kamar.issues.release');
    Route::post('/kamar/kendala/{issue}/buka-lagi', [RoomIssueController::class, 'reopen'])->name('kamar.issues.reopen');
    Route::get('/laporan', [ReportController::class, 'index'])->name('laporan.index');
    Route::get('/laporan/ekspor.xlsx', [ReportController::class, 'export'])->name('laporan.export');
    Route::get('/laporan/masalah', [ReportController::class, 'issues'])->name('laporan.issues');
    Route::get('/laporan/masalah/tugas', [ReportController::class, 'task'])->name('laporan.task');
    Route::post('/laporan/tindak-lanjut/{item}', [ReportController::class, 'followUp'])->name('laporan.followup');
    Route::get('/laporan/bulanan', [ReportController::class, 'monthly'])->name('laporan.monthly');
    Route::get('/laporan/parameter', [ReportController::class, 'parameters'])->name('laporan.parameters');
    Route::get('/laporan/kamar', [ReportController::class, 'rooms'])->name('laporan.rooms');
    Route::get('/laporan/kamar/gedung', [ReportController::class, 'building'])->name('laporan.building');
    Route::get('/laporan/kamar/{room}', [ReportController::class, 'room'])->whereNumber('room')->name('laporan.room');
    Route::get('/laporan/data', [ReportController::class, 'data'])->name('laporan.data');
    Route::get('/laporan/data/ekspor.xlsx', [ReportController::class, 'dataExport'])->name('laporan.data.export');
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

        Route::post('/denah', [FloorPlanController::class, 'store'])->name('plans.store');
        Route::get('/denah/{plan}', [FloorPlanController::class, 'edit'])->name('plans.edit');
        Route::put('/denah/{plan}', [FloorPlanController::class, 'update'])->name('plans.update');
        Route::delete('/denah/{plan}', [FloorPlanController::class, 'destroy'])->name('plans.destroy');
        Route::put('/denah/{plan}/area', [FloorPlanController::class, 'saveAreas'])->name('plans.areas');
        Route::get('/kamar', [RoomController::class, 'index'])->name('rooms.index');
        Route::post('/kamar', [RoomController::class, 'storeRoom'])->name('rooms.store');
        Route::post('/kamar/gedung', [RoomController::class, 'setBuilding'])->name('rooms.building');
        Route::get('/kamar/impor', [RoomController::class, 'importForm'])->name('rooms.import');
        Route::post('/kamar/impor', [RoomController::class, 'importPreview'])->name('rooms.import.preview');
        Route::post('/kamar/impor/simpan', [RoomController::class, 'importApply'])->name('rooms.import.apply');
        Route::delete('/kamar/impor', [RoomController::class, 'importCancel'])->name('rooms.import.cancel');
        Route::get('/kamar/contoh.xlsx', [RoomController::class, 'template'])->name('rooms.template');
        Route::put('/kamar/{room}', [RoomController::class, 'updateRoom'])->name('rooms.update');
        Route::post('/kategori-kendala', [RoomController::class, 'storeCategory'])->name('categories.store');
        Route::put('/kategori-kendala/{category}', [RoomController::class, 'updateCategory'])->name('categories.update');

        Route::get('/tugas', [TaskController::class, 'index'])->name('tasks.index');
        Route::post('/tugas/jabatan', [TaskController::class, 'storePosition'])->name('positions.store');
        Route::get('/tugas/jabatan/{position}', [TaskController::class, 'show'])->name('tasks.show');
        Route::put('/tugas/jabatan/{position}', [TaskController::class, 'updatePosition'])->name('positions.update');
        Route::delete('/tugas/jabatan/{position}', [TaskController::class, 'destroyPosition'])->name('positions.destroy');
        Route::post('/tugas/jabatan/{position}/tugas', [TaskController::class, 'storeTask'])->name('tasks.store');
        Route::post('/tugas/jabatan/{position}/dari-kamus', [TaskController::class, 'importFromCatalog'])->name('tasks.import-catalog');
        Route::put('/tugas/{task}', [TaskController::class, 'updateTask'])->name('tasks.update');
        Route::delete('/tugas/{task}', [TaskController::class, 'destroyTask'])->name('tasks.destroy');
        Route::post('/tugas/{task}/geser', [TaskController::class, 'moveTask'])->name('tasks.move');
    });
});
