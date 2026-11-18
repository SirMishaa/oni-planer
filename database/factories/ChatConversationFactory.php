<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChatConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatConversation> */
final class ChatConversationFactory extends Factory
{
    protected $model = ChatConversation::class;

    public function definition(): array
    {
        return [
            'colony_guid' => $this->faker->uuid(),
            'claude_session_id' => null,
        ];
    }
}
