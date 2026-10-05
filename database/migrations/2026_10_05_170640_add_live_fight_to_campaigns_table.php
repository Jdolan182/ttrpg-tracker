<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fight the DM is running right now, for the player view. Kept apart from saved encounters:
     * it follows the tracker live, while a saved copy only changes when the DM presses Save.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->jsonb('live')->nullable()->after('invite_token');
            $table->timestamp('live_updated_at')->nullable()->after('live');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['live', 'live_updated_at']);
        });
    }
};
