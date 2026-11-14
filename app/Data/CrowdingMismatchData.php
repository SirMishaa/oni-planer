<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** A value of the overcrowding rule where the planner and the game disagree, for the Uplink inspector. */
final class CrowdingMismatchData extends Data
{
    public function __construct(
        public string $zoneName,
        public string $regionId,
        /** Empty for a value of the whole region. */
        public string $prefabId,
        #[LiteralTypeScriptType("'occupied' | 'overcrowded' | 'confined' | 'modifier'")]
        /** @var 'occupied'|'overcrowded'|'confined'|'modifier' */
        public string $field,
        #[LiteralTypeScriptType('number | boolean')]
        public int|bool $game,
        #[LiteralTypeScriptType('number | boolean')]
        public int|bool $planner,
        /** ISO 8601 time of the check. */
        public string $at,
    ) {}

    /**
     * @return list<self>
     */
    public static function fromZone(string $zoneName, mixed $mismatches): array
    {
        $rows = [];
        foreach (is_array($mismatches) ? $mismatches : [] as $row) {
            $rows[] = new self($zoneName, $row['regionId'], $row['prefabId'], $row['field'], $row['game'], $row['planner'], $row['at']);
        }

        return $rows;
    }
}
