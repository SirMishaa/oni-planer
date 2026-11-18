<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Models\ChatQuestion;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;

/**
 * Answers a player's question from the game. The conversation itself lives in the Claude Code
 * session; the earlier answered questions are exposed as messages only so the gateway can
 * replay them when a session cannot be resumed.
 */
final class ColonyChatAgent implements Agent, Conversational, HasProviderOptions
{
    use Promptable;

    /**
     * @param  list<string>  $imagePaths  Absolute paths of the captures Claude should look at.
     */
    public function __construct(private readonly ChatQuestion $question, private readonly array $imagePaths = []) {}

    public function instructions(): string
    {
        return <<<'TEXT'
        You answer a player of Oxygen Not Included, asking from inside the game about their own colony.
        You are read-only: never change anything and never run commands that write.
        Be brief and concrete: a few short sentences, no preamble.
        Answer in the language of the question.
        The game panel renders only a small markdown subset: **bold**, *italic*, `code`, bullet lists (lines starting with "- ") and numbered lists (lines starting with "1. "). Use nothing else: no headings, no tables, no links. Write short paragraphs.
        Write every reference to a game entity (building, critter, plant, egg, duplicant) as [[<label>|#<instanceId>]], with several ids separated by commas when the label covers several entities, for example [[2 Pacu|#611036,#611040]]. The player clicks it to select the entity in the game.
        Take the instance ids from the `oni` MCP tools, where they are printed after #. Never invent an id: when you do not know the id, write the entity's name as plain text.
        Start from the context given with the question, then use the `oni` MCP tools (zone_overview, zone_map, zone_cells, entity, lookup, zone_captures) for anything it does not cover.
        Pass the zone ids of the context as they are written there. Say so when data is missing or stale instead of guessing.
        TEXT;
    }

    /**
     * The conversation's earlier answered questions, oldest first.
     *
     * @return list<Message>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->previousQuestions() as $previous) {
            $messages[] = new UserMessage($previous->question);
            $messages[] = new AssistantMessage((string) $previous->answer);
        }

        return $messages;
    }

    /**
     * @return array{session_id: ?string, resume: bool, image_paths: list<string>}
     */
    public function providerOptions(Lab|string $provider): array
    {
        $sessionId = $this->question->conversation->claude_session_id;

        return [
            'session_id' => $sessionId,
            'resume' => $sessionId !== null && $this->previousQuestions()->isNotEmpty(),
            'image_paths' => $this->imagePaths,
        ];
    }

    /** @return Collection<int, ChatQuestion> */
    private function previousQuestions(): Collection
    {
        return $this->question->conversation->questions()
            ->where('status', ChatQuestion::ANSWERED)
            ->whereKeyNot($this->question->id)
            ->oldest('created_at')
            ->get();
    }
}
