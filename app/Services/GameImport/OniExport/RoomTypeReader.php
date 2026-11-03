<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

/**
 * Turns `db.json` `roomTypes` into room type records for
 * {@see \App\Services\GameImport\RoomTypeImporter::importRecords()}.
 *
 * The export carries constraints as compiled delegates, so their matching
 * logic is not available. What survives is the localized text, which is mapped
 * back to STRINGS keys through `po_string.json` (same language as the text):
 *
 * - `name_key` comes from `ROOMS.TYPES.*.NAME`; room types without one (added
 *   by mods) are skipped.
 * - `description_key` comes from `ROOMS.CRITERIA.*.DESCRIPTION`, null when the
 *   text has no unique match (mod criteria, shared descriptions, templates).
 * - `tags` are the `<link>` targets of the constraint's English
 *   `conflictDescription`, matched case-insensitively against building tags
 *   and ids from `building.json`; an unknown target is kept as written.
 *   Alternatives ("A or B") are all listed, `min` is `times_required` of
 *   building constraints. Room-level constraints (prohibitions, decor) carry
 *   no count: read them through `description_key`.
 * - The minimum/maximum size constraints become `size_min` / `size_max`.
 * - `stomp_in_conflict` (constraints a stronger room type overrides) is not kept.
 *
 * @phpstan-type Requirement array{description_key: string|null, tags: list<string>, min: int|null, max: int|null}
 * @phpstan-type RoomTypeRecord array{
 *     room_type_id: string,
 *     category: string,
 *     name_key: string,
 *     requirements: list<Requirement>,
 *     size_min: int|null,
 *     size_max: int|null,
 *     bonus_effect: string|null,
 *     priority: int,
 * }
 */
final readonly class RoomTypeReader
{
    private const string MINIMUM_SIZE_KEY = 'STRINGS.ROOMS.CRITERIA.MINIMUM_SIZE.DESCRIPTION';

    private const string MAXIMUM_SIZE_KEY = 'STRINGS.ROOMS.CRITERIA.MAXIMUM_SIZE.DESCRIPTION';

    /** Link target prefixes that name a tag class rather than a tag. */
    private const array LINK_PREFIXES = ['BUILDCATEGORYREQUIREMENTCLASS', 'REQUIREMENTCLASS'];

    public function __construct(private OniExport $export, private ModCatalog $mods) {}

    /** @return list<RoomTypeRecord> */
    public function records(): array
    {
        $rooms = $this->export->json('po_string.json')['ROOMS'] ?? [];
        $nameKeys = $this->keysByText($rooms, 'ROOMS.TYPES.', '.NAME');
        $descriptionKeys = $this->keysByText($rooms, 'ROOMS.CRITERIA.', '.DESCRIPTION');
        $sizePatterns = [
            'min' => $this->sizePattern($rooms[mb_substr(self::MINIMUM_SIZE_KEY, 8)] ?? null),
            'max' => $this->sizePattern($rooms[mb_substr(self::MAXIMUM_SIZE_KEY, 8)] ?? null),
        ];
        $knownTags = $this->knownTags();

        $records = [];

        foreach ($this->export->json('db.json')['roomTypes'] ?? [] as $room) {
            $nameKey = $nameKeys[$room['Name']] ?? null;

            if ($nameKey === null) {
                continue;
            }

            $requirements = [];
            $sizes = ['min' => null, 'max' => null];

            foreach ([$room['primary_constraint'] ?? null, ...($room['additional_constraints'] ?? [])] as $constraint) {
                if ($constraint === null) {
                    continue;
                }

                $size = $this->size((string) $constraint['description'], $sizePatterns);

                if ($size !== null) {
                    $sizes[$size[0]] = $size[1];

                    continue;
                }

                $requirements[] = $this->requirement($constraint, $descriptionKeys, $knownTags);
            }

            $records[] = [
                'room_type_id' => (string) $room['Id'],
                'category' => (string) ($room['category']['Id'] ?? 'None'),
                'name_key' => 'STRINGS.'.$nameKey,
                'requirements' => $requirements,
                'size_min' => $sizes['min'],
                'size_max' => $sizes['max'],
                'bonus_effect' => ($room['effects'][0] ?? null) !== null ? (string) $room['effects'][0] : null,
                'priority' => (int) $room['priority'],
            ];
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $constraint
     * @param  array<string, string|null>  $descriptionKeys
     * @param  array<string, string>  $knownTags
     * @return Requirement
     */
    private function requirement(array $constraint, array $descriptionKeys, array $knownTags): array
    {
        $descriptionKey = $descriptionKeys[$constraint['description']] ?? null;
        preg_match_all('/<link="([^"]+)"/', (string) $constraint['conflictDescription'], $links);

        $tags = [];

        foreach ($links[1] as $link) {
            $tags[] = $knownTags[mb_strtolower(str_replace(self::LINK_PREFIXES, '', $link))] ?? str_replace(self::LINK_PREFIXES, '', $link);
        }

        return [
            'description_key' => $descriptionKey !== null ? 'STRINGS.'.$descriptionKey : null,
            'tags' => $tags,
            'min' => $constraint['building_criteria'] !== null ? (int) $constraint['times_required'] : null,
            'max' => null,
        ];
    }

    /**
     * Text → STRINGS key without prefix; null when several keys share the text.
     *
     * @param  array<string, string>  $strings
     * @return array<string, string|null>
     */
    private function keysByText(array $strings, string $prefix, string $suffix): array
    {
        $byText = [];

        foreach ($strings as $key => $text) {
            if (str_starts_with($key, $prefix) && str_ends_with($key, $suffix)) {
                $byText[$text] = array_key_exists($text, $byText) ? null : $key;
            }
        }

        return $byText;
    }

    /** Regex capturing the tile count of a localized `{0}` size description. */
    private function sizePattern(?string $template): ?string
    {
        return $template === null ? null : '/^'.str_replace('\{0\}', '(\d+)', preg_quote($template, '/')).'$/u';
    }

    /**
     * @param  array{min: string|null, max: string|null}  $patterns
     * @return array{0: 'min'|'max', 1: int}|null
     */
    private function size(string $description, array $patterns): ?array
    {
        foreach ($patterns as $bound => $pattern) {
            if ($pattern !== null && preg_match($pattern, $description, $matches) === 1) {
                return [$bound, (int) $matches[1]];
            }
        }

        return null;
    }

    /** @return array<string, string> lowercase → tag or building id as written in `building.json` */
    private function knownTags(): array
    {
        $known = [];

        foreach ($this->export->json('building.json')['bBuildingDefList'] ?? [] as $def) {
            $known[mb_strtolower((string) $def['name'])] = (string) $def['name'];

            foreach ($def['tags'] ?? [] as $tag) {
                $known[mb_strtolower((string) $tag['Name'])] = (string) $tag['Name'];
            }
        }

        return $known;
    }
}
