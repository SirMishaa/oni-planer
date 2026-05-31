<?php

declare(strict_types=1);

namespace App\Services\GameImport;

use App\Models\Attributes\GameData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;

final class GameDataTables
{
    /**
     * Game-data tables with no Eloquent model (pivots). These cannot carry the
     * #[GameData] attribute, so they are listed explicitly.
     *
     * @var list<string>
     */
    private const array PIVOT_TABLES = ['plant_variant_mutations', 'codexables'];

    /** @return list<string> */
    public static function all(): array
    {
        $tables = [];

        foreach (File::allFiles(app_path('Models')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            /** @var class-string $class */
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (! class_exists($class)) {
                continue;
            }
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->getAttributes(GameData::class) === []) {
                continue;
            }

            /** @var Model $instance */
            $instance = $reflection->newInstanceWithoutConstructor();
            $tables[] = $instance->getTable();
        }

        return array_values(array_unique([...$tables, ...self::PIVOT_TABLES]));
    }
}
