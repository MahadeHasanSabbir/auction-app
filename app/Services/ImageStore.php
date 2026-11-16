<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stores uploaded pictures on the public disk.
 *
 * An upload is never saved as received: it is decoded, turned upright, scaled
 * down and written again as WebP under a random name. Whatever else was in
 * the file (scripts in a disguised upload, location data in a phone photo)
 * does not survive that, and the original file name is never used.
 */
final class ImageStore
{
    private const QUALITY = 82;

    /**
     * @param  string  $directory  folder on the public disk, e.g. "avatars"
     * @param  int  $maxSide  longest side of the stored picture, in pixels
     * @param  bool  $square  crop to a centred square (avatars)
     * @return string the stored path, relative to the public disk
     */
    public function store(UploadedFile $file, string $directory, int $maxSide = 1600, bool $square = false): string
    {
        $bytes = $file->get();
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('The upload is not a picture this server can read.');
        }

        $image = $this->upright($image, $file);
        $image = $this->resize($image, $maxSide, $square);

        ob_start();
        imagewebp($image, null, self::QUALITY);
        $encoded = (string) ob_get_clean();

        $path = trim($directory, '/').'/'.Str::random(40).'.webp';

        Storage::disk('public')->put($path, $encoded);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk('public')->delete($path);
        }
    }

    /** Phone cameras record rotation as a tag; apply it, since the tag is dropped. */
    private function upright(GdImage $image, UploadedFile $file): GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/tiff'], true)) {
            return $image;
        }

        $exif = @exif_read_data($file->getRealPath());
        $angle = match (is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private function resize(GdImage $image, int $maxSide, bool $square): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Source rectangle: the whole picture, or its centred square.
        $side = min($width, $height);
        [$srcX, $srcY, $srcW, $srcH] = $square
            ? [intdiv($width - $side, 2), intdiv($height - $side, 2), $side, $side]
            : [0, 0, $width, $height];

        $scale = min(1, $maxSide / max($srcW, $srcH));
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));

        $canvas = imagecreatetruecolor($dstW, $dstH);

        if (! $canvas instanceof GdImage) {
            throw new InvalidArgumentException('The picture could not be processed.');
        }

        // Keep transparency instead of turning it black.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        imagecopyresampled($canvas, $image, 0, 0, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH);

        return $canvas;
    }
}
