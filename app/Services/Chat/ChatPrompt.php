<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\ChatContextItem;
use App\Models\ChatQuestion;
use App\Models\Colony;
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
     * Absolute paths of the captures taken for this question.
     *
     * @return list<string>
     */
    public static function imagePaths(ChatQuestion $question): array
    {
        $paths = [];

        foreach ($question->contextItems()->where('wants_capture', true)->whereNotNull('zone_id')->get() as $item) {
            $capture = self::freshCapture($item, $question);

            if ($capture !== null) {
                $paths[] = Storage::disk('public')->path($capture);
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

        $capture = $item->wants_capture ? self::freshCapture($item, $question) : null;

        if ($capture !== null) {
            $lines[] = '  Capture (image, read it): '.Storage::disk('public')->path($capture);
        }

        return $lines;
    }

    private static function freshCapture(ChatContextItem $item, ChatQuestion $question): ?string
    {
        $capture = $item->zone?->captures()
            ->when($question->context_requested_at !== null, fn ($query) => $query->where('taken_at', '>=', $question->context_requested_at))
            ->first();

        return $capture?->path;
    }
}
