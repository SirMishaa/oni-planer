<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

use Illuminate\Support\Facades\File;

/** Read access to a synced OniExtract export directory. */
final readonly class OniExport
{
    public function __construct(public string $path) {}

    /**
     * Decoded content of `database/<file>`.
     *
     * @return array<mixed>
     *
     * @throws OniExportMissing
     */
    public function json(string $file): array
    {
        $fullPath = $this->path.'/database/'.$file;

        if (! File::isFile($fullPath)) {
            throw new OniExportMissing("OniExtract export file not found: {$fullPath}");
        }

        /** @var array<mixed> */
        return json_decode(File::get($fullPath), true, flags: JSON_THROW_ON_ERROR);
    }

    public function buildVersion(): string
    {
        return (string) ($this->json('building.json')['buildVersion'] ?? 'unknown');
    }

    public function hasBuildingImages(): bool
    {
        return File::isDirectory($this->path.'/ui_image') && File::glob($this->path.'/ui_image/*.png') !== [];
    }
}
