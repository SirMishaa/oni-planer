<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Colony;
use App\Models\Zone;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * The colony and zones catalogs are resolved per request (closures), in the current
     * locale, for `useT()` on the pages.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => fn (): string => app()->getLocale(),
            'translations' => fn (): array => ['colony' => trans('colony'), 'zones' => trans('zones')],
            'navZones' => fn (): array => $this->navZones(),
        ];
    }

    /**
     * The active zones of the most recently seen colony, by name, for the side navigation.
     *
     * @return list<array{id: int, name: string}>
     */
    private function navZones(): array
    {
        $colonyGuid = Colony::query()->latest('last_seen_at')->value('guid');

        if ($colonyGuid === null) {
            return [];
        }

        return Zone::query()->active()->where('colony_guid', $colonyGuid)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Zone $zone): array => ['id' => $zone->id, 'name' => $zone->name])
            ->values()
            ->all();
    }
}
