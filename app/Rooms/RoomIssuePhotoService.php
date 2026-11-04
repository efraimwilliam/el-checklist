<?php

namespace App\Rooms;

use App\Models\RoomIssuePhoto;
use App\Models\RoomIssueUpdate;
use App\Models\User;
use App\Support\PhotoReencoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Foto bukti pekerjaan kendala kamar: diolah ulang (PhotoReencoder), disimpan di disk privat, dilayani lewat controller setelah pemeriksaan akses. */
class RoomIssuePhotoService
{
    /** Foto per catatan status. */
    public const MAX_PER_UPDATE = 3;

    private function disk()
    {
        return Storage::disk(config('checklist.photos.disk'));
    }

    /** @throws \Illuminate\Validation\ValidationException */
    public function store(RoomIssueUpdate $update, UploadedFile $file, User $by): RoomIssuePhoto
    {
        $bytes = PhotoReencoder::jpeg($file, 'photos');
        $path = "room-issue-photos/{$update->room_issue_id}/".Str::uuid().'.jpg';
        $this->disk()->put($path, $bytes);

        return $update->photos()->create(['path' => $path, 'size' => strlen($bytes), 'created_by' => $by->id]);
    }

    public function contents(RoomIssuePhoto $photo): ?string
    {
        return $this->disk()->exists($photo->path) ? $this->disk()->get($photo->path) : null;
    }

    /** Menghapus foto yang lebih tua dari $days hari (berkas dan barisnya). @return int jumlah foto dihapus */
    public function prune(int $days): int
    {
        $deleted = 0;
        RoomIssuePhoto::where('created_at', '<', now()->subDays($days))->each(function (RoomIssuePhoto $photo) use (&$deleted) {
            $this->disk()->delete($photo->path);
            $photo->delete();
            $deleted++;
        });

        return $deleted;
    }
}
