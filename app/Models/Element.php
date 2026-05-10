<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ElementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'element_id', 'state', 'molar_mass', 'toxicity', 'material_category',
    'tags', 'low_temp_transition_target', 'high_temp_transition_target',
    'name', 'description', 'dlc_id', 'is_disabled',
])]
final class Element extends Model
{
    /** @use HasFactory<ElementFactory> */
    use HasFactory;

    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

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

    public function casts(): array
    {
        return [
            'tags' => 'array',
            'is_disabled' => 'boolean',
            'name' => 'array',
            'description' => 'array',
        ];
    }
}
