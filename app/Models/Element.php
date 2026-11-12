<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubstanceTexture;
use App\Models\Attributes\GameData;
use App\Models\Concerns\HasCodexEntries;
use Database\Factories\ElementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

#[GameData]
#[Fillable([
    'element_id', 'state', 'molar_mass', 'toxicity', 'material_category',
    'tags', 'low_temp_transition_target', 'high_temp_transition_target',
    'name', 'description', 'dlc_id', 'is_disabled', 'mod_id', 'ui_color',
    'render_color', 'substance_texture', 'uses_caustics', 'glows', 'is_metallic',
    'is_opaque_liquid', 'liquid_scroll_speed', 'max_mass', 'terrain_texture',
])]
final class Element extends Model
{
    use HasCodexEntries;

    /** @use HasFactory<ElementFactory> */
    use HasFactory;

    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    public function resolveRouteBinding($value, $field = null): ?static
    {
        return self::query()
            ->where('element_id', Str::studly(is_string($value) ? $value : ''))
            ->where('is_disabled', false)
            ->first();
    }

    /** @return BelongsTo<Mod, $this> */
    public function mod(): BelongsTo
    {
        return $this->belongsTo(Mod::class);
    }

    /** @return HasOne<ElementThermalProperty, $this> */
    public function thermalProperties(): HasOne
    {
        return $this->hasOne(ElementThermalProperty::class, 'element_id', 'element_id');
    }

    /** @return HasOne<ElementGasProperty, $this> */
    public function gasProperties(): HasOne
    {
        return $this->hasOne(ElementGasProperty::class, 'element_id', 'element_id');
    }

    /** @return HasOne<ElementLiquidProperty, $this> */
    public function liquidProperties(): HasOne
    {
        return $this->hasOne(ElementLiquidProperty::class, 'element_id', 'element_id');
    }

    /** @return HasOne<ElementSolidProperty, $this> */
    public function solidProperties(): HasOne
    {
        return $this->hasOne(ElementSolidProperty::class, 'element_id', 'element_id');
    }

    /** @return HasOne<ElementSpecialProperty, $this> */
    public function specialProperties(): HasOne
    {
        return $this->hasOne(ElementSpecialProperty::class, 'element_id', 'element_id');
    }

    /** @return HasOne<FoodProperty, $this> */
    public function foodProperties(): HasOne
    {
        return $this->hasOne(FoodProperty::class, 'element_id', 'element_id');
    }

    /** @return HasOne<Element, $this> */
    public function lowTempTransitionElement(): HasOne
    {
        return $this->hasOne(self::class, 'element_id', 'low_temp_transition_target');
    }

    /** @return HasOne<Element, $this> */
    public function highTempTransitionElement(): HasOne
    {
        return $this->hasOne(self::class, 'element_id', 'high_temp_transition_target');
    }

    /** The UI colour as a lowercase `#rrggbb` string, or null when unknown. */
    public function uiColorHex(): ?string
    {
        return $this->ui_color === null ? null : sprintf('#%06x', $this->ui_color);
    }

    /** The render colour as a lowercase `#rrggbbaa` string, or null when unknown. */
    public function renderColorHex(): ?string
    {
        return $this->render_color === null ? null : sprintf('#%08x', $this->render_color);
    }

    public function casts(): array
    {
        return [
            'tags' => 'array',
            'is_disabled' => 'boolean',
            'ui_color' => 'integer',
            'render_color' => 'integer',
            'substance_texture' => SubstanceTexture::class,
            'uses_caustics' => 'boolean',
            'glows' => 'boolean',
            'is_metallic' => 'boolean',
            'is_opaque_liquid' => 'boolean',
            'liquid_scroll_speed' => 'float',
            'max_mass' => 'float',
            'name' => 'array',
            'description' => 'array',
        ];
    }
}
