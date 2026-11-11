<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ColonyStatusData;
use App\Data\UplinkMessageData;
use App\Models\Colony;
use App\Models\UplinkMessage;
use App\Services\Viability\ViabilityPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Pages fed by the Uplink channel: food viability and the message inspector. */
final readonly class ColonyController
{
    /** Columns the Uplink status reads; never the large `state`. */
    private const array STATUS_COLUMNS = ['id', 'name', 'cycle', 'schema', 'last_seen_at'];

    public function __construct(private ViabilityPresenter $presenter) {}

    public function viability(): Response
    {
        $colony = $this->latestColony([...self::STATUS_COLUMNS, 'state']);

        return Inertia::render('Colony/Viability', [
            'status' => $this->status($colony),
            'viability' => $colony instanceof Colony ? $this->presenter->present($colony->state ?? []) : null,
        ]);
    }

    public function uplink(Request $request): Response
    {
        $messages = UplinkMessage::query()
            ->select(['id', 'event', 'colony_guid', 'colony_name', 'cycle', 'size', 'outcome', 'reason', 'section_errors', 'received_at'])
            ->latest('received_at')
            ->latest('id')
            ->get();

        $selected = $request->integer('message') > 0
            ? UplinkMessage::query()->find($request->integer('message'), ['id', 'payload'])
            : null;

        return Inertia::render('Colony/Uplink', [
            'status' => $this->status($this->latestColony(self::STATUS_COLUMNS)),
            'messages' => $messages->map(UplinkMessageData::fromModel(...))->all(),
            'selected' => $selected instanceof UplinkMessage ? ['id' => $selected->id, 'payload' => $selected->payload] : null,
        ]);
    }

    /**
     * The colony shown is the most recently seen one.
     *
     * @param  list<string>  $columns
     */
    private function latestColony(array $columns): ?Colony
    {
        return Colony::query()->latest('last_seen_at')->first($columns);
    }

    private function status(?Colony $colony): ColonyStatusData
    {
        $latestMessage = UplinkMessage::query()->latest('received_at')->latest('id')->first(['outcome', 'reason']);

        $state = match (true) {
            ! $latestMessage instanceof UplinkMessage && ! $colony instanceof Colony => 'never',
            $latestMessage?->outcome === 'refused' && $latestMessage->reason === 'schema_newer' => 'newer_schema',
            ! $colony instanceof Colony => 'no_colony',
            default => 'ok',
        };

        return new ColonyStatusData(
            state: $state,
            colonyName: $colony?->name,
            cycle: $colony?->cycle,
            lastSeenAt: $colony?->last_seen_at?->toIso8601String(),
            schema: $colony?->schema,
        );
    }
}
