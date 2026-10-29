<?php

namespace App\Checklists;

use App\Models\ChecklistItem;
use App\Models\ChecklistItemPhoto;
use App\Models\User;
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
    /** Batas piksel yang masih aman diproses (mencegah gambar raksasa menghabiskan memori). */
    private const MAX_PIXELS = 40_000_000;

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
        $contents = (string) file_get_contents($file->getRealPath());
        $size = @getimagesizefromstring($contents);
        if (! $size || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['photo' => __('File bukan foto yang valid atau terlalu besar.')]);
        }
        $image = @imagecreatefromstring($contents);
        if (! $image) {
            throw ValidationException::withMessages(['photo' => __('File bukan foto yang valid.')]);
        }

        // Putar sesuai EXIF (foto langsung dari kamera), lalu kecilkan.
        $orientation = $size[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') ? (@exif_read_data($file->getRealPath())['Orientation'] ?? 1) : 1;
        $image = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
        $max = (int) config('checklist.photos.max_edge');
        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) > $max) {
            $image = imagescale($image, $width >= $height ? $max : (int) round($width * $max / $height), $height > $width ? $max : (int) round($height * $max / $width));
        }

        // Latar putih untuk PNG / WebP transparan, lalu JPEG baru tanpa metadata.
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        ob_start();
        imagejpeg($canvas, null, 82);

        return (string) ob_get_clean();
    }
}
