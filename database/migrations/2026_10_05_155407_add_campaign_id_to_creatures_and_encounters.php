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
        // A campaign's party (player characters) and its encounters. Deleting a campaign leaves both in
        // place, just no longer in a campaign.
        Schema::table('creatures', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('encounters', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('creatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::table('encounters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
    }
};
