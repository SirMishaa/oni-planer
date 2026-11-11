<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Description('Write the Uplink mod settings (Reverb URL, channel, interval) into the mod folder')]
#[Signature('uplink:install {--path= : Mod folder, defaults to game.uplink_mod_path} {--host=127.0.0.1 : Host the game reaches Reverb on}')]
final class UplinkInstallCommand extends Command
{
    public function handle(): int
    {
        $path = mb_rtrim((string) ($this->option('path') ?: config('game.uplink_mod_path')), '/');
        $key = (string) config('reverb.apps.apps.0.key');

        if ($key === '') {
            $this->error(__('colony.install.key_missing'));

            return self::FAILURE;
        }

        if (! File::isDirectory($path)) {
            $this->error(__('colony.install.folder_missing', ['path' => $path]));

            return self::FAILURE;
        }

        $file = $path.'/uplink.json';
        File::put($file, json_encode($this->settings($key), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $this->info(__('colony.install.written', ['file' => $file]));

        return self::SUCCESS;
    }

    /**
     * @return array{url: string, channel: string, intervalSeconds: int}
     */
    private function settings(string $key): array
    {
        $scheme = config('reverb.apps.apps.0.options.scheme') === 'https' ? 'wss' : 'ws';

        return [
            'url' => sprintf(
                '%s://%s:%d/app/%s?protocol=7&client=uplink&version=1',
                $scheme,
                (string) $this->option('host'),
                (int) config('reverb.servers.reverb.port'),
                $key,
            ),
            'channel' => 'uplink',
            'intervalSeconds' => 5,
        ];
    }
}
