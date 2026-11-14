<?php

declare(strict_types=1);

namespace App\Actions\Uplink;

use App\Events\ZoneUpdated;
use App\Models\UplinkCommand;
use App\Models\Zone;
use App\Models\ZoneCapture;
use App\Services\GameImport\OniExport\AssetConverter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Stores a zone capture: converts the PNG the mod wrote in its folder (`game.uplink_mod_path`,
 * `captures/{zoneId}/{fileName}`) to WebP quality 90 on the public disk (about 85 % lighter,
 * no visible difference), records it and keeps the last
 * {@see ZoneCapture::KEEP} of the zone. A failed capture, or one whose file is missing, records
 * nothing; a manual one marks its command refused with the reason. Open pages hear of both
 * (`zone.updated`, kind `capture`). Idempotent: the mod resends a capture until it is acked, so
 * a second delivery of a stored capture (its file already moved) changes nothing.
 */
final class StoreZoneCapture
{
    /** WebP quality of the stored captures, the game renders' own. */
    private const int WEBP_QUALITY = 90;

    public function __construct(private readonly AssetConverter $converter) {}

    /**
     * @param  array{colonyId: string, zoneId: string, gameSeconds: int|float, trigger: string, commandId?: string|null, signature?: string|null, fileName?: string, width?: int, height?: int, failure?: string}  $message
     * @return bool false when the zone is unknown (deleted in the game since)
     */
    public function handle(array $message): bool
    {
        $zone = Zone::query()
            ->where('colony_guid', $message['colonyId'])
            ->where('zone_guid', $message['zoneId'])
            ->first();

        if ($zone === null) {
            return false;
        }

        $failure = $message['failure'] ?? null;
        $source = config()->string('game.uplink_mod_path').'/captures/'.$message['zoneId'].'/'.($message['fileName'] ?? '');
        $path = 'zone-captures/'.$zone->zone_guid.'/'.pathinfo($message['fileName'] ?? '', PATHINFO_FILENAME).'.webp';

        if ($failure === null && $zone->captures()->where('path', $path)->exists()) {
            File::delete($source);

            return true;
        }

        if ($failure === null && ! File::isFile($source)) {
            $failure = 'capture file missing';
        }

        if ($failure === null) {
            try {
                $this->converter->pngToWebp($source, Storage::disk('public')->path($path), self::WEBP_QUALITY);
            } catch (RuntimeException) {
                $failure = 'capture file unreadable';
            }
        }

        if ($failure !== null) {
            $this->failCommand($message['commandId'] ?? null, $zone, $failure);
        } else {
            File::delete($source);

            $zone->captures()->create([
                'path' => $path,
                'taken_at' => now(),
                'game_seconds' => (float) $message['gameSeconds'],
                'layout_signature' => $message['signature'] ?? null,
                'trigger' => $message['trigger'],
                'command_id' => $message['commandId'] ?? null,
                'width' => (int) ($message['width'] ?? 0),
                'height' => (int) ($message['height'] ?? 0),
            ]);

            $this->prune($zone);
        }

        ZoneUpdated::dispatch($zone->zone_guid, $zone->colony_guid, 'capture');

        return true;
    }

    private function failCommand(?string $commandId, Zone $zone, string $failure): void
    {
        if ($commandId === null || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $commandId) !== 1) {
            return;
        }

        UplinkCommand::query()
            ->whereKey($commandId)
            ->where('colony_guid', $zone->colony_guid)
            ->update(['status' => UplinkCommand::REFUSED, 'reason' => mb_substr($failure, 0, 255), 'acked_at' => now()]);
    }

    /** Deletes the captures beyond the most recent {@see ZoneCapture::KEEP}: their rows, and their files when no kept row uses them. */
    private function prune(Zone $zone): void
    {
        $stale = $zone->captures()->skip(ZoneCapture::KEEP)->take(PHP_INT_MAX)->get();

        foreach ($stale as $capture) {
            $capture->delete();

            if (! ZoneCapture::query()->where('path', $capture->path)->exists()) {
                Storage::disk('public')->delete($capture->path);
            }
        }
    }
}
