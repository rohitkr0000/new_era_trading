<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team size / team business per member, refreshed every minute by `php artisan net:stats`.
 * Leaderboard positions then cost two indexed COUNT queries per dashboard instead of an O(members) computation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedInteger('team_size')->default(0)->index();
            $t->decimal('team_business', 18, 2)->default(0)->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['team_size', 'team_business']));
    }
};
