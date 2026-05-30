<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codex_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('codex_id')->unique();
            $table->string('category');
            $table->string('parent_codex_id')->nullable();
            $table->json('title');
            $table->string('icon_asset')->nullable();
            $table->json('content');
            $table->string('dlc_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codex_entries');
    }
};
