<?php

return [
    // Tugas berstatus Pending wajib diberi catatan alasan (sama dengan pengaturan workbook lama).
    'pending_note_required' => (bool) env('CHECKLIST_PENDING_NOTE_REQUIRED', true),

    // Foto bukti per tugas. Nilai awal; Administrator mengubahnya di Pengaturan (tersimpan di tabel settings).
    'photos' => [
        // optional = boleh; pending = wajib untuk tugas Pending; all = wajib untuk semua tugas.
        'requirement' => 'optional',
        // Hari sebelum foto dihapus otomatis (0 = tidak pernah). Isian checklist tetap ada, hanya fotonya yang dihapus.
        'retention_days' => 90,
        // Batas per tugas, dan ukuran sisi terpanjang hasil olahan server (piksel).
        'per_task' => 3,
        'max_edge' => 1600,
        'disk' => 'local',
    ],
];
