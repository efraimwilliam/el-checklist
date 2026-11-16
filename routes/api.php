<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\ChatController as AppChatController;
use App\Http\Controllers\Api\ChecklistController as AppChecklistController;
use App\Http\Controllers\Api\ChronologyController;
use App\Http\Controllers\Api\GuestRequestController as AppGuestRequestController;
use App\Http\Controllers\Api\RoomIssueController as AppRoomIssueController;
use App\Http\Controllers\AppReleaseController;
use App\Http\Controllers\BulkAssignController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistItemController;
use App\Http\Controllers\ChecklistPhotoController;
use App\Http\Controllers\ChronologyReportController;
use App\Http\Controllers\EmployeePhotoController;
use App\Http\Controllers\FillController;
use App\Http\Controllers\GuestRequestController;
use App\Http\Controllers\RoomIssueController;
use App\Http\Middleware\EnsureActiveToken;
use Illuminate\Support\Facades\Route;

// Aplikasi Android (token Sanctum). Simpan isian / catatan / foto memakai controller web yang sama.
Route::prefix('v1/app')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    // Pembaruan aplikasi (tanpa login): versi terbaru yang diterbitkan `aplikasi:rilis`.
    Route::get('/versi', [AppReleaseController::class, 'version'])->middleware('throttle:60,1');

    Route::middleware(['auth:sanctum', EnsureActiveToken::class, 'throttle:120,1'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/push-token', [AuthController::class, 'pushToken']);

        Route::get('/checklists', [AppChecklistController::class, 'index']);
        Route::get('/riwayat', [AppChecklistController::class, 'history']);
        Route::get('/checklists/{checklist}', [AppChecklistController::class, 'show']);
        Route::patch('/checklists/{checklist}', [ChecklistController::class, 'meta']);
        Route::patch('/checklists/{checklist}/items/{item}', [ChecklistItemController::class, 'update'])->scopeBindings();
        Route::post('/checklists/{checklist}/items/{item}/photos', [ChecklistPhotoController::class, 'store'])->scopeBindings()->middleware('throttle:60,1');
        // Papan Isi checklist (HOD / HR / Administrator): mulai, buat sekaligus, review lewat controller web yang sama.
        Route::get('/papan', [BoardController::class, 'index']);
        Route::post('/papan/mulai', [FillController::class, 'start']);
        Route::post('/papan/semua', [BulkAssignController::class, 'store']);
        Route::post('/checklists/{checklist}/review', [ChecklistController::class, 'review']);
        Route::get('/checklists/{checklist}/photos/{photo}', [ChecklistPhotoController::class, 'show']);
        Route::delete('/checklists/{checklist}/photos/{photo}', [ChecklistPhotoController::class, 'destroy']);

        // Kendala: baca lewat Api\RoomIssueController, aksi memakai controller web yang sama (izin di RoomIssuePolicy).
        Route::get('/kendala', [AppRoomIssueController::class, 'index']);
        Route::get('/kendala/form', [AppRoomIssueController::class, 'form']);
        Route::post('/kendala', [RoomIssueController::class, 'store']);
        Route::get('/kendala/{issue}', [AppRoomIssueController::class, 'show'])->whereNumber('issue');
        Route::post('/kendala/{issue}/ambil', [RoomIssueController::class, 'claim']);
        Route::post('/kendala/{issue}/lepas', [RoomIssueController::class, 'release']);
        Route::post('/kendala/{issue}/status', [RoomIssueController::class, 'progress'])->middleware('throttle:60,1');
        Route::get('/kendala/{issue}/foto/{photo}', [RoomIssueController::class, 'photo']);

        // Obrolan: daftar / pesan / kontak lewat Api\ChatController; kirim, foto, hapus lewat controller web yang sama.
        Route::get('/obrolan', [AppChatController::class, 'index']);
        Route::get('/obrolan/kontak', [AppChatController::class, 'contacts']);
        Route::post('/obrolan/pribadi/{person}', [AppChatController::class, 'direct'])->whereNumber('person');
        Route::get('/obrolan/{thread}/pesan', [AppChatController::class, 'messages'])->whereNumber('thread');
        Route::post('/obrolan/{thread}/pesan', [ChatController::class, 'send'])->whereNumber('thread')->middleware('throttle:60,1');
        Route::delete('/obrolan/{thread}/pesan/{message}', [ChatController::class, 'destroy'])->whereNumber(['thread', 'message']);
        Route::get('/obrolan/{thread}/foto/{message}', [ChatController::class, 'photo'])->whereNumber(['thread', 'message']);
        Route::post('/obrolan/{thread}/mengetik', [ChatController::class, 'typing'])->whereNumber('thread')->middleware('throttle:60,1');
        Route::get('/obrolan/pegawai/{employee}/foto', EmployeePhotoController::class)->whereNumber('employee')->middleware('can:chat');

        // Chronology Report: daftar / detail lewat Api\ChronologyController; simpan & finalisasi lewat controller web yang sama.
        Route::get('/chronology', [ChronologyController::class, 'index']);
        Route::post('/chronology', [ChronologyReportController::class, 'store']);
        Route::get('/chronology/{report}', [ChronologyController::class, 'show'])->whereNumber('report');
        Route::put('/chronology/{report}', [ChronologyReportController::class, 'update'])->whereNumber('report');
        Route::post('/chronology/{report}/final', [ChronologyReportController::class, 'finalize'])->whereNumber('report');

        // Permintaan tamu: sama polanya dengan Kendala (izin di GuestRequestPolicy).
        Route::get('/permintaan', [AppGuestRequestController::class, 'index']);
        Route::get('/permintaan/form', [AppGuestRequestController::class, 'form']);
        Route::post('/permintaan', [GuestRequestController::class, 'store']);
        Route::post('/permintaan/{guestRequest}/ambil', [GuestRequestController::class, 'claim'])->whereNumber('guestRequest');
        Route::post('/permintaan/{guestRequest}/selesai', [GuestRequestController::class, 'complete'])->whereNumber('guestRequest');
        Route::post('/permintaan/{guestRequest}/lepas', [GuestRequestController::class, 'release'])->whereNumber('guestRequest');
        Route::delete('/permintaan/{guestRequest}', [GuestRequestController::class, 'destroy'])->whereNumber('guestRequest');
    });
});
