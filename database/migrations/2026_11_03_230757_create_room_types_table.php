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
        Schema::create('room_types', function (Blueprint $table): void {
            $table->id();
            $table->string('room_type_id')->unique();
            $table->string('category');
            $table->string('name_key');
            $table->json('requirements');
            $table->unsignedInteger('size_min')->nullable();
            $table->unsignedInteger('size_max')->nullable();
            $table->string('bonus_effect')->nullable();
            $table->integer('priority');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
