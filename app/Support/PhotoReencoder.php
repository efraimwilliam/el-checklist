<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Mengolah ulang foto unggahan di server (GD): diputar sesuai EXIF, dikecilkan, dan dijadikan JPEG baru sehingga data lokasi / metadata
 * terbuang dan berkas yang bukan gambar sungguhan ditolak. Dipakai foto checklist dan foto kendala kamar.
 */
final class PhotoReencoder
{
    /** Batas piksel yang masih aman diproses (mencegah gambar raksasa menghabiskan memori). */
    private const MAX_PIXELS = 40_000_000;

    /** @throws ValidationException */
    public static function jpeg(UploadedFile $file, string $errorKey = 'photo'): string
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $size = @getimagesizefromstring($contents);
        if (! $size || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages([$errorKey => __('File bukan foto yang valid atau terlalu besar.')]);
        }
        $image = @imagecreatefromstring($contents);
        if (! $image) {
            throw ValidationException::withMessages([$errorKey => __('File bukan foto yang valid.')]);
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
