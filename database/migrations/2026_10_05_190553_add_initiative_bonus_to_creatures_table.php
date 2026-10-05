<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a creature adds to its initiative roll. Null means "work it out" (DEX in d20 games), so
     * existing creatures and the SRD keep rolling as before.
     */
    public function up(): void
    {
        Schema::table('creatures', function (Blueprint $table) {
            $table->smallInteger('initiative_bonus')->nullable()->after('ac');
        });
    }

    public function down(): void
    {
        Schema::table('creatures', function (Blueprint $table) {
            $table->dropColumn('initiative_bonus');
        });
    }
};
