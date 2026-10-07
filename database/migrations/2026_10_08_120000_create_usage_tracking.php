<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counting how the site is used, as totals only (see App\Support\Visits and the privacy page).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The day an account last used the site; "active this month" counts these.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->index();
        });

        // One row per day: how many different people visited, how many of them were logged in, and the
        // most campaigns in live combat at the same time.
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('visitors')->default(0);
            $table->unsignedInteger('accounts')->default(0);
            $table->unsignedInteger('peak_live_tables')->default(0);
        });

        // Who has already been counted today, so nobody counts twice. A one-way code made with a key that
        // changes every day, so it can't be traced back to anyone or link one day to the next; rows older
        // than yesterday are deleted.
        Schema::create('visit_keys', function (Blueprint $table) {
            $table->date('day');
            $table->char('key', 64);
            $table->primary(['day', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_keys');
        Schema::dropIfExists('daily_stats');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_active_at');
        });
    }
};
