<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Uplink\SendUplinkCommand;
use App\Models\UplinkCommand;
use App\Models\Zone;
use App\Models\ZoneCapture;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

#[Description('Ask the game for a picture of a zone and print where it was stored')]
#[Signature('uplink:capture {zone : Zone id or zone guid} {--timeout=60 : Seconds to wait for the capture}')]
final class UplinkCaptureCommand extends Command
{
    private const int POLL_MILLISECONDS = 500;

    public function handle(SendUplinkCommand $sendCommand): int
    {
        $key = (string) $this->argument('zone');
        $zone = Zone::query()
            ->when(ctype_digit($key), fn ($query) => $query->whereKey((int) $key), fn ($query) => $query->where('zone_guid', $key))
            ->first();

        if ($zone === null) {
            $this->error("No zone {$key}.");

            return self::FAILURE;
        }

        $timeout = max(1, (int) $this->option('timeout'));
        $command = $sendCommand->handle($zone->colony_guid, 'zone.capture', ['zoneId' => $zone->zone_guid], $timeout);
        $this->info("Asked the game for a capture of {$zone->name}…");

        for ($poll = 0; $poll < $timeout * 1000 / self::POLL_MILLISECONDS; $poll++) {
            $capture = ZoneCapture::query()->where('command_id', $command->id)->first();

            if ($capture !== null) {
                $this->line(Storage::disk('public')->path($capture->path));
                $layoutSignature = $zone->fresh()?->layout['signature'] ?? null;
                $this->info($capture->layout_signature !== null && $capture->layout_signature === $layoutSignature
                    ? 'The capture matches the current site layout.'
                    : 'The capture was taken on an older layout than the site shows.');

                return self::SUCCESS;
            }

            $command->refresh();
            if (in_array($command->status, [UplinkCommand::REFUSED, UplinkCommand::EXPIRED], true)) {
                $this->error("The game did not capture the zone: {$command->status}".($command->reason !== null ? " ({$command->reason})" : '').'.');

                return self::FAILURE;
            }

            Sleep::for(self::POLL_MILLISECONDS)->milliseconds();
        }

        $this->error("No capture after {$timeout} s: is the game running and linked?");

        return self::FAILURE;
    }
}
