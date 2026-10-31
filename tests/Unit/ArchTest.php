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
]);
arch()->preset()->security()->ignoring([
    'assert',
]);

arch('controllers')
    ->expect('App\Http\Controllers')
    ->not->toBeUsed();

//
