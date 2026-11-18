<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\ChatQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatQuestion> */
final class ChatQuestionFactory extends Factory
{
    protected $model = ChatQuestion::class;

    public function definition(): array
    {
        return [
            'chat_conversation_id' => ChatConversation::factory(),
            'question' => $this->faker->sentence().'?',
            'status' => ChatQuestion::PENDING,
        ];
    }
}
