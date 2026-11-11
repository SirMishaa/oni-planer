<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UplinkMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One colony-state message received over the Uplink channel, stored or
 * refused (with a reason code), kept for the message inspector. User data,
 * not game data. Only `received_at` is tracked, no `updated_at`.
 */
#[Fillable([
    'event', 'colony_guid', 'colony_name', 'cycle', 'size', 'outcome', 'reason',
    'section_errors', 'payload', 'received_at',
])]
final class UplinkMessage extends Model
{
    /** @use HasFactory<UplinkMessageFactory> */
    use HasFactory;

    public $timestamps = false;

    public function casts(): array
    {
        return [
            'cycle' => 'integer',
            'size' => 'integer',
            'section_errors' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
