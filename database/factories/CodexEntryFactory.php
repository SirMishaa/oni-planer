<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CodexEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CodexEntry> */
final class CodexEntryFactory extends Factory
{
    protected $model = CodexEntry::class;

    public function definition(): array
    {
        $id = $this->faker->unique()->word();

        return [
            'codex_id' => $id,
            'category' => 'Systems',
            'parent_codex_id' => null,
            'title' => ['en' => ucfirst($id)],
            'icon_asset' => null,
            'content' => [],
            'dlc_id' => null,
        ];
    }
}
