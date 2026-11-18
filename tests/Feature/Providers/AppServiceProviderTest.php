<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\DevCommands;

afterEach(function (): void {
    DevCommands::except();
});

it('builds production assets before starting Octane in preview mode', function (): void {
    $this->app->instance('env', 'local');
    config(['game.preview_server' => true]);

    (new AppServiceProvider($this->app))->boot();

    $commands = collect(DevCommands::commands())->keyBy('name');

    expect($commands->keys()->all())->not->toContain('vite');
    expect($commands->get('server')['command'])
        ->toContain('NODE_ENV=production bun run build && rm -f ')
        ->toContain(' && php artisan octane:start --watch --server=frankenphp');
});

it('starts Vite alongside Octane in normal development mode', function (): void {
    $this->app->instance('env', 'local');
    config(['game.preview_server' => false]);

    (new AppServiceProvider($this->app))->boot();

    $commands = collect(DevCommands::commands())->keyBy('name');

    expect($commands->get('vite')['command'])->toContain('bun run dev');
    expect($commands->get('server')['command'])
        ->toContain('php artisan octane:start --watch --server=frankenphp')
        ->not->toContain('bun run build');
});

it('keeps a long-lived worker running for each Uplink queue', function (): void {
    $this->app->instance('env', 'local');

    (new AppServiceProvider($this->app))->boot();

    $commands = collect(DevCommands::commands())->keyBy('name');

    expect($commands->get('reliable')['command'])->toContain('while true; do php artisan queue:work redis --queue=uplink-reliable ');
    expect($commands->get('state')['command'])->toContain('while true; do php artisan queue:work redis --queue=uplink-state,default ');
    expect($commands->get('chat')['command'])->toContain('while true; do php artisan queue:work redis --queue=chat ');
});
