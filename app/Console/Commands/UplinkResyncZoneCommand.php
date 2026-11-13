<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Uplink\SendUplinkCommand;
use App\Models\Zone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Ask the Uplink mod to resend a zone (layout and both states)')]
#[Signature('uplink:resync-zone {zone : Zone id or zone guid}')]
final class UplinkResyncZoneCommand extends Command
{
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

        $command = $sendCommand->handle($zone->colony_guid, 'zone.resync', ['zoneId' => $zone->zone_guid]);
        $this->info("Sent zone.resync {$command->id} for zone {$zone->name}; the mod answers within a few seconds if the game is running.");

        return self::SUCCESS;
    }
}
