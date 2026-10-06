<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Email verification starts here. Accounts made before it existed never got a link to click, so
     * they count as verified rather than being locked out of their own things.
     */
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    /**
     * Nothing to undo: there's no telling which accounts this verified.
     */
    public function down(): void {}
};
