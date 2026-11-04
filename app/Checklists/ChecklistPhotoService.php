<?php

namespace App\Checklists;

use App\Models\ChecklistItem;
use App\Models\ChecklistItemPhoto;
use App\Models\User;
use App\Support\PhotoReencoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Foto bukti per tugas. Setiap foto diolah ulang di server (GD): diputar sesuai EXIF, dikecilkan, dan disimpan sebagai JPEG baru,
 * sehingga data lokasi / metadata terbuang dan berkas yang bukan gambar sungguhan ditolak. Berkas ada di disk privat.
 */
class ChecklistPhotoService
{
    public function __construct(private ChecklistSettings $settings) {}

    private function disk()
    {
        return Storage::disk(config('checklist.photos.disk'));
    }

    /** @throws ValidationException */
    public function store(ChecklistItem $item, UploadedFile $file, User $by): ChecklistItemPhoto
    {
        if ($item->photos()->count() >= $this->settings->photosPerTask()) {
            throw ValidationException::withMessages(['photo' => __('Maksimal :n foto per tugas.', ['n' => $this->settings->photosPerTask()])]);
        }

        $path = "checklist-photos/{$item->checklist_id}/".Str::uuid().'.jpg';
        $bytes = $this->reencode($file);
        $this->disk()->put($path, $bytes);

        return $item->photos()->create(['path' => $path, 'size' => strlen($bytes), 'created_by' => $by->id]);
    }

    public function delete(ChecklistItemPhoto $photo): void
    {
        $this->disk()->delete($photo->path);
        $photo->delete();
    }

    /** Menghapus foto yang lebih tua dari $days hari (berkas dan barisnya). @return int jumlah foto dihapus */
    public function prune(int $days): int
    {
        $deleted = 0;
        ChecklistItemPhoto::where('created_at', '<', now()->subDays($days))->each(function (ChecklistItemPhoto $photo) use (&$deleted) {
            $this->delete($photo);
            $deleted++;
        });

        return $deleted;
    }

    /** @throws ValidationException */
    private function reencode(UploadedFile $file): string
    {
        return PhotoReencoder::jpeg($file, 'photo');
    }
}
