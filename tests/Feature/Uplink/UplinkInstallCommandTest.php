<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->modPath = sys_get_temp_dir().'/uplink-install-'.uniqid();
    File::ensureDirectoryExists($this->modPath);

    config([
        'reverb.apps.apps.0.key' => 'test-app-key',
        'reverb.apps.apps.0.options.scheme' => 'http',
        'reverb.servers.reverb.port' => 8080,
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->modPath);
});

it('writes the uplink settings into the mod folder', function (): void {
    $this->artisan('uplink:install', ['--path' => $this->modPath])->assertSuccessful();

    $settings = json_decode(File::get($this->modPath.'/uplink.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($settings)->toBe([
        'url' => 'ws://127.0.0.1:8080/app/test-app-key?protocol=7&client=uplink&version=1',
        'channel' => 'uplink',
        'intervalSeconds' => 5,
    ]);
});

it('uses wss and the given host when Reverb is served over https', function (): void {
    config(['reverb.apps.apps.0.options.scheme' => 'https']);

    $this->artisan('uplink:install', ['--path' => $this->modPath, '--host' => '192.168.1.10'])->assertSuccessful();

    $settings = json_decode(File::get($this->modPath.'/uplink.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($settings['url'])->toBe('wss://192.168.1.10:8080/app/test-app-key?protocol=7&client=uplink&version=1');
});

it('refuses to create a missing mod folder', function (): void {
    app()->setLocale('en');
    $missing = $this->modPath.'/missing';

    $this->artisan('uplink:install', ['--path' => $missing])
        ->expectsOutputToContain('Build and install the Uplink mod first')
        ->assertExitCode(1);

    expect(File::isDirectory($missing))->toBeFalse();
});

it('fails naming REVERB_APP_KEY when the key is missing', function (): void {
    config(['reverb.apps.apps.0.key' => null]);
    app()->setLocale('en');

    $this->artisan('uplink:install', ['--path' => $this->modPath])
        ->expectsOutputToContain('REVERB_APP_KEY')
        ->assertExitCode(1);

    expect(File::exists($this->modPath.'/uplink.json'))->toBeFalse();
});

it('translates the output into the application locale', function (): void {
    app()->setLocale('fr');
    $missing = $this->modPath.'/missing';
    $french = __('colony.install.folder_missing', ['path' => $missing], 'fr');

    expect($french)->not->toBe(__('colony.install.folder_missing', ['path' => $missing], 'en'))
        ->and($french)->not->toBe('colony.install.folder_missing');

    $this->artisan('uplink:install', ['--path' => $missing])
        ->expectsOutputToContain($french)
        ->assertExitCode(1);
});
