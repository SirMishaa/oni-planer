<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\ChatContextItem;
use App\Models\ChatQuestion;
use App\Models\Colony;
use App\Models\ZoneCapture;
use Illuminate\Support\Facades\Storage;

/** Writes the prompt Claude gets: the colony, the question and what the player pointed at. */
final class ChatPrompt
{
    /**
     * @param  list<int>  $missingItemIds  Context items whose data did not arrive (the context wait gave up).
     */
    public static function build(ChatQuestion $question, array $missingItemIds = []): string
    {
        $colony = Colony::query()->where('guid', $question->conversation->colony_guid)->first();

        $lines = [
            $colony === null
                ? 'Colony: unknown (no data received).'
                : sprintf('Colony: %s, cycle %d.', $colony->name, $colony->cycle),
            '',
            'Question: '.$question->question,
        ];

        $missing = [];

        foreach ($question->contextItems()->with('zone')->orderBy('id')->get() as $item) {
            $reason = $item->refused_reason ?? (in_array($item->id, $missingItemIds, true) ? 'the game did not send it in time' : null);

            if ($reason !== null) {
                $missing[] = sprintf('%s "%s" (%s)', $item->kind, $item->label, $reason);

                continue;
            }

            $lines[] = '';
            array_push($lines, ...self::block($item, $question));
        }

        if ($missing !== []) {
            $lines[] = '';
            $lines[] = 'Could not be read from the game: '.implode('; ', $missing).'.';
        }

        return implode("\n", $lines);
    }

    /**
     * Absolute paths of the captures the prompt points Claude at.
     *
     * @return list<string>
     */
    public static function imagePaths(ChatQuestion $question): array
    {
        $paths = [];

        foreach ($question->contextItems()->whereNotNull('zone_id')->with('zone')->get() as $item) {
            $capture = self::capture($item, $question);

            if ($capture !== null) {
                $paths[] = Storage::disk('public')->path($capture->path);
            }
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private static function block(ChatContextItem $item, ChatQuestion $question): array
    {
        $lines = [sprintf('Context (%s): %s', $item->kind, $item->label)];

        if ($item->layer_hint !== null) {
            $lines[] = '  Layer hint (a hint only, not a filter): '.$item->layer_hint;
        }

        if ($item->zone_id !== null) {
            $lines[] = sprintf('  Zone id for zone_overview, zone_map and entity: %d', $item->zone_id);
        }

        if ($item->instance_id !== null) {
            $lines[] = '  Instance id: '.$item->instance_id;
        }

        $capture = self::capture($item, $question);

        if ($capture !== null) {
            $lines[] = sprintf(
                '  Capture (image, read it; taken %s, cycle %d): %s',
                $capture->taken_at->diffForHumans(),
                $capture->cycle(),
                Storage::disk('public')->path($capture->path),
            );
        }

        return $lines;
    }

    /**
     * A requested capture is the newest one taken since the question asked for it; a stored zone
     * that did not ask for a new one gets its latest existing capture.
     */
    private static function capture(ChatContextItem $item, ChatQuestion $question): ?ZoneCapture
    {
        $zone = $item->zone;

        if ($zone === null) {
            return null;
        }

        if ($item->wants_capture) {
            return $zone->latestCapture($question->context_requested_at);
        }

        return $zone->transient ? null : $zone->latestCapture();
    }
}
