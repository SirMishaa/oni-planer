<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codexables', function (Blueprint $table): void {
            $table->foreignId('codex_entry_id')->constrained()->cascadeOnDelete();
            $table->morphs('codexable');
            $table->unique(['codex_entry_id', 'codexable_type', 'codexable_id'], 'codexables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codexables');
    }
};
