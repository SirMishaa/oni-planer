<?php

declare(strict_types=1);

arch()->preset()->php();
arch()->preset()->strict()->ignoring([
    // Overrides a protected hook of the Spatie base provider.
    App\Providers\TypeScriptTransformerServiceProvider::class,
]);
arch()->preset()->laravel()->ignoring([
    // PHP attributes used to tag game-data models, not models themselves.
    App\Models\Attributes::class,
    // Exception, not a model.
    App\Services\GameImport\OniExport\OniExportMissing::class,
    // Two named pages (viability, uplink) fed by the same colony status.
    App\Http\Controllers\ColonyController::class,
]);
arch()->preset()->security()->ignoring([
    'assert',
]);

arch('controllers')
    ->expect('App\Http\Controllers')
    ->not->toBeUsed();

//
