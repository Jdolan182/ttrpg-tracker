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
        // Players in a campaign (the DM isn't listed here; they own it).
        Schema::create('campaign_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The party character this player has claimed, if any. One player per character (Postgres
            // allows many NULLs in a unique index, so unclaimed players don't clash).
            $table->unsignedBigInteger('character_id')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id']);
            $table->unique(['campaign_id', 'character_id']);
            $table->foreign('character_id')->references('id')->on('creatures')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_user');
    }
};
