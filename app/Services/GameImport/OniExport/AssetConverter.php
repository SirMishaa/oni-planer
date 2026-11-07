<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

use Illuminate\Support\Facades\File;
use RuntimeException;

/** Converts exported PNG renders to WebP, keeping the alpha channel. */
final class AssetConverter
{
    public function pngToWebp(string $source, string $destination, int $quality = 90): void
    {
        $image = @imagecreatefrompng($source);

        if ($image === false) {
            throw new RuntimeException("Cannot read PNG: {$source}");
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        File::ensureDirectoryExists(dirname($destination));

        if (! imagewebp($image, $destination, $quality)) {
            throw new RuntimeException("Cannot write WebP: {$destination}");
        }
    }
}
