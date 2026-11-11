<?php

declare(strict_types=1);

namespace App\Data\Viability;

use Spatie\LaravelData\Data;

/** Food stock projection of one harvest scenario. */
final class ViabilityScenarioData extends Data
{
    public function __construct(
        /** Whole cycles fed before a shortage, null when the horizon is reached. */
        public ?int $autonomy,
        /** Name of the first duplicant to run short, null when nobody does. */
        public ?string $firstShort,
        /** @var list<float> stock (kcal) at the start of each cycle from now */
        public array $stockByCycle,
    ) {}
}
