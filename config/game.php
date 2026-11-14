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
     * Workshop ids of the mods whose content is imported, or '*' for every mod
     * found in the export (the colony map and the future build menu need the
     * buildings of every mod the player runs). Content of an unlisted mod is skipped.
     *
     * @var list<string>
     */
    'supported_mods' => ['*'],

    /* Local dev directory (Windows side) the Uplink mod is deployed to. */
    'uplink_mod_path' => env('UPLINK_MOD_PATH', '/mnt/c/Users/misha/Documents/Klei/OxygenNotIncluded/mods/dev/Uplink'),

    /* Determine whether it use the Vite preview server or not */
    'preview_server' => env('ONI_PREVIEW_SERVER', false),
];
