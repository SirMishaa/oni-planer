<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class ThermalPropertyData extends Data
{
    public function __construct(
        public float $specific_heat_capacity,
        public float $thermal_conductivity,
        public ?float $low_temp,
        public ?float $high_temp,
        public float $default_temperature,
    ) {}
}
