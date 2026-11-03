<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\RoomType;

final readonly class RoomTypeImporter
{
    /**
     * Persists room type records produced by the OniExtract room type reader.
     *
     * @param  list<array<string, mixed>>  $records
     */
    public function importRecords(array $records): void
    {
        foreach ($records as $raw) {
            RoomType::query()->create([
                'room_type_id' => $raw['room_type_id'],
                'category' => $raw['category'],
                'name_key' => $raw['name_key'],
                'requirements' => $raw['requirements'] ?? [],
                'size_min' => $raw['size_min'] ?? null,
                'size_max' => $raw['size_max'] ?? null,
                'bonus_effect' => $raw['bonus_effect'] ?? null,
                'priority' => $raw['priority'] ?? 0,
            ]);
        }
    }
}
