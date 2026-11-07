<?php

declare(strict_types=1);

return [
    /*
     * Directory written by the OniExtract mod (Windows side), mirrored by
     * `game:sync-export` into `export_path`.
     */
    'export_source' => env('ONI_EXPORT_SOURCE', '/mnt/c/Users/misha/Documents/Klei/OxygenNotIncluded/export'),

    /* Steam workshop mods directory (Windows side). */
    'mods_source' => env('ONI_MODS_SOURCE', '/mnt/c/Users/misha/Documents/Klei/OxygenNotIncluded/mods/Steam'),

    'export_path' => base_path('game-data/oniextract/current'),

    'mods_path' => base_path('game-data/mods'),

    /* Public directory the converted building, connection and element images are written to. */
    'assets_path' => public_path('assets/game'),

    /*
     * Workshop ids of the mods whose content is imported. Content of any other
     * mod found in the export is skipped.
     *
     * @var list<string>
     */
    'supported_mods' => ['3557584850'],
];
