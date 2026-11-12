<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The game's `Substance.n` enum: the liquid and gas shader texture family of an element.
 */
#[TypeScript]
enum SubstanceTexture: string
{
    case None = 'None';
    case Magma = 'Magma';
    case MoltenMetal = 'MoltenMetal';
    case Polluted = 'Polluted';
    case Oil = 'Oil';
    case Thick = 'Thick';
    case Sap = 'Sap';
    case CrystalFragments = 'CrystalFragments';
    case Ovolene = 'Ovolene';
    case Mucus = 'Mucus';
    case Ink = 'Ink';
    case PollutedBrine = 'PollutedBrine';
}
