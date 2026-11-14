<?php

declare(strict_types=1);

use App\Services\Zones\ZoneGeometry;

it('fills a footprint upward from the bottom-left anchor', function (): void {
    $cell = fn (int $x, int $y): int => ZoneGeometry::cell($x, $y, 606);

    expect(ZoneGeometry::footprint($cell(114, 201), 2, 2, 606))
        ->toBe([$cell(114, 201), $cell(115, 201), $cell(114, 202), $cell(115, 202)]);
});

it('converts a cell to world coordinates and back', function (): void {
    expect(ZoneGeometry::xy(201 * 606 + 114, 606))->toBe([114, 201])
        ->and(ZoneGeometry::cell(114, 201, 606))->toBe(201 * 606 + 114);
});
