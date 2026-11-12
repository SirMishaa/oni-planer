<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ZoneListItemData;
use App\Models\Colony;
use App\Models\Zone;
use App\Services\Zones\ZonePresenter;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** The zones the player marked in the game: the list of the current colony and one page per zone. */
final readonly class ZoneController
{
    /** Columns the list reads; never the large layout and slow state. */
    private const array LIST_COLUMNS = ['id', 'name', 'world_id', 'cell_count', 'orphaned', 'fast_state', 'layout_at', 'slow_state_at', 'fast_state_at'];

    public function __construct(private ZonePresenter $presenter) {}

    /** Active zones of the most recently seen colony; archived zones are hidden. */
    public function index(): Response
    {
        $colony = Colony::query()->latest('last_seen_at')->first(['guid', 'name']);

        /** @var Collection<int, Zone> $zones */
        $zones = $colony instanceof Colony
            ? Zone::query()->active()->where('colony_guid', $colony->guid)->orderBy('name')->orderBy('id')->get(self::LIST_COLUMNS)
            : new Collection;

        return Inertia::render('Zones/Index', [
            'colonyName' => $colony?->name,
            'zones' => $zones->map(function (Zone $zone): ZoneListItemData {
                $problems = $this->presenter->problemSummary($zone);

                return new ZoneListItemData(
                    id: $zone->id,
                    name: $zone->name,
                    worldId: $zone->world_id,
                    cellCount: $zone->cell_count,
                    orphaned: $zone->orphaned,
                    problemCount: $problems['count'],
                    problemsPartial: $problems['partial'],
                    updatedAt: collect([$zone->layout_at, $zone->slow_state_at, $zone->fast_state_at])->filter()->max()?->toIso8601String(),
                );
            })->all(),
            'echo' => ['channel' => 'uplink', 'event' => '.zone.updated'],
        ]);
    }

    /** One zone; an archived zone (gone from its save) is not found. */
    public function show(Zone $zone): Response
    {
        abort_if($zone->archived_at !== null, 404);

        return Inertia::render('Zones/Show', $this->presenter->present($zone));
    }
}
