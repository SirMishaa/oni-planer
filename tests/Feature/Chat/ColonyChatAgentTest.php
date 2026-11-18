<?php

declare(strict_types=1);

use App\Ai\Agents\ColonyChatAgent;
use App\Models\ChatQuestion;

it('limits answers to the markdown subset the game panel renders', function (): void {
    $instructions = (new ColonyChatAgent(new ChatQuestion))->instructions();

    expect($instructions)
        ->toContain('**bold**')
        ->toContain('*italic*')
        ->toContain('`code`')
        ->toContain('- ')
        ->toContain('1. ')
        ->toContain('no headings, no tables, no links')
        ->toContain('short paragraphs');
});

it('asks for entity references as links carrying the instance ids from the tools', function (): void {
    $instructions = (new ColonyChatAgent(new ChatQuestion))->instructions();

    expect($instructions)
        ->toContain('[[2 Pacu|#611036,#611040]]')
        ->toContain('instance ids')
        ->toContain('Never invent an id')
        ->toContain('plain text');
});
