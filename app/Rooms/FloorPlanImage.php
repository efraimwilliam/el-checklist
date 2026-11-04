<?php

namespace App\Rooms;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan gambar denah: dicek sebagai gambar sungguhan (GD), dikecilkan (maks 3000 px) dan disimpan sebagai PNG baru tanpa metadata
 * di disk privat. Dilayani lewat controller, tidak lewat folder publik.
 */
final class FloorPlanImage
{
    public const MAX_EDGE = 3000;

    private const MAX_PIXELS = 60_000_000;

    /** @return array{path: string, width: int, height: int} */
    public static function store(UploadedFile $file): array
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $size = @getimagesizefromstring($contents);
        if (! $size || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['image' => __('Berkas bukan gambar denah yang valid atau terlalu besar.')]);
        }
        $image = @imagecreatefromstring($contents);
        if (! $image) {
            throw ValidationException::withMessages(['image' => __('Berkas bukan gambar denah yang valid.')]);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) > self::MAX_EDGE) {
            $scaled = imagescale($image, $width >= $height ? self::MAX_EDGE : (int) round($width * self::MAX_EDGE / $height), $height > $width ? self::MAX_EDGE : (int) round($height * self::MAX_EDGE / $width));
            $image = $scaled ?: $image;
            $width = imagesx($image);
            $height = imagesy($image);
        }

        // Latar putih untuk PDF / PNG transparan, lalu PNG baru tanpa metadata.
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        ob_start();
        imagepng($canvas, null, 6);
        $bytes = (string) ob_get_clean();

        $path = 'floor-plans/'.Str::uuid().'.png';
        Storage::disk(config('checklist.photos.disk'))->put($path, $bytes);

        return ['path' => $path, 'width' => $width, 'height' => $height];
    }

    public static function contents(string $path): ?string
    {
        $disk = Storage::disk(config('checklist.photos.disk'));

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    public static function delete(string $path): void
    {
        Storage::disk(config('checklist.photos.disk'))->delete($path);
    }
}
