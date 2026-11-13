<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commands the site sends to the mod, with the mod's answer.
     */
    public function up(): void
    {
        Schema::create('uplink_commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('colony_guid');
            $table->string('type');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->string('reason')->nullable();
            $table->dateTime('issued_at');
            $table->dateTime('expires_at');
            $table->dateTime('acked_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['colony_guid', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uplink_commands');
    }
};
