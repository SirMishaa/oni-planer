<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('uplink_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('event')->nullable();
            $table->string('colony_guid')->nullable();
            $table->string('colony_name')->nullable();
            $table->integer('cycle')->nullable();
            $table->integer('size');
            $table->string('outcome');
            $table->string('reason')->nullable();
            $table->json('section_errors');
            $table->longText('payload');
            $table->dateTime('received_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uplink_messages');
    }
};
