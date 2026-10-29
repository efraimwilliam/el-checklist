<?php

return [
    // Tugas berstatus Pending wajib diberi catatan alasan (sama dengan pengaturan workbook lama).
    'pending_note_required' => (bool) env('CHECKLIST_PENDING_NOTE_REQUIRED', true),
];
