<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UplinkCommandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A command the site sent to the mod (`uplink.command`) and the mod's answer
 * (`client-command.ack`): pending until acked done or refused, or expired
 * once past `expires_at` without an answer. Expiry is lazy, see expireStale().
 */
#[Fillable(['colony_guid', 'type', 'payload', 'status', 'reason', 'issued_at', 'expires_at', 'acked_at'])]
final class UplinkCommand extends Model
{
    /** @use HasFactory<UplinkCommandFactory> */
    use HasFactory;

    use HasUuids;

    public const string PENDING = 'pending';

    public const string DONE = 'done';

    public const string REFUSED = 'refused';

    public const string EXPIRED = 'expired';

    /**
     * Marks pending commands past their expiry as expired. Called before
     * commands are read or sent, so no scheduler is needed.
     */
    public static function expireStale(): int
    {
        return self::query()
            ->where('status', self::PENDING)
            ->where('expires_at', '<=', now())
            ->update(['status' => self::EXPIRED]);
    }

    public function casts(): array
    {
        return [
            'payload' => 'array',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'acked_at' => 'datetime',
        ];
    }

    /** A pending command past its expiry reads as expired even before expireStale() wrote it. */
    public function getStatusAttribute(?string $status): ?string
    {
        return $status === self::PENDING && $this->expires_at?->lte(now()) === true ? self::EXPIRED : $status;
    }
}
