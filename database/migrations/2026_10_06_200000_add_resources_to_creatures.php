<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named pools a creature's actions can spend from (legendary actions, spell slots, mana, focus…).
     */
    public function up(): void
    {
        Schema::table('creatures', function (Blueprint $table) {
            $table->jsonb('resources')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('creatures', function (Blueprint $table) {
            $table->dropColumn('resources');
        });
    }
};
