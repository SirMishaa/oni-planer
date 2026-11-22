<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a zone plan change does: add a building, remove one, or replace one (a remove plus an add).
 */
enum ZonePlanChangeKind: string
{
    case Add = 'add';
    case Remove = 'remove';
    case Replace = 'replace';
}
