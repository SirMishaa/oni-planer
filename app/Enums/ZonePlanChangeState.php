<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a zone plan change stands against the real layout. Applied only concerns adds and replaces.
 */
enum ZonePlanChangeState: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Conflicted = 'conflicted';
}
