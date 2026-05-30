<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\CodexEntry;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasCodexEntries
{
    /** @return MorphToMany<CodexEntry, $this> */
    public function codexEntries(): MorphToMany
    {
        return $this->morphToMany(CodexEntry::class, 'codexable');
    }
}
