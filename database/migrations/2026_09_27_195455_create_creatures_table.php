<?php

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
        Schema::create('creatures', function (Blueprint $table) {
            $table->id();
            // Null for built-in SRD creatures that every user (and guest) can see.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind'); // monster, npc or player
            $table->string('name');
            $table->string('summary')->default('');
            $table->string('rating')->default(''); // CR, level or tier, whatever the game system uses
            $table->unsignedInteger('hp');
            $table->unsignedInteger('ac');
            $table->string('speed')->default('');
            // Lists rather than objects: jsonb doesn't preserve key order, and stat order matters.
            $table->jsonb('stats'); // [{label, value}]
            $table->jsonb('traits'); // [{name, description}]
            $table->jsonb('actions'); // [{name, description}]
            $table->timestamps();

            $table->index(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('creatures');
    }
};
