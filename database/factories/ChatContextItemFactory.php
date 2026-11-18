<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChatContextItem;
use App\Models\ChatQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatContextItem> */
final class ChatContextItemFactory extends Factory
{
    protected $model = ChatContextItem::class;

    public function definition(): array
    {
        return [
            'chat_question_id' => ChatQuestion::factory(),
            'kind' => 'zone',
            'label' => $this->faker->words(2, true),
            'zone_id' => null,
            'instance_id' => null,
            'layer_hint' => null,
            'wants_capture' => false,
            'refused_reason' => null,
        ];
    }
}
