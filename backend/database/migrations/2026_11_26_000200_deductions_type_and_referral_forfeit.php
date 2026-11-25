<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Direct/retopup/level income that would go to a sponsor or upline who isn't active (no package bought yet, or
 * blocked) was simply never credited to anyone — it vanished with no record. It now shows up here too, as a
 * 'referral_forfeit' row, so admins can see the company kept it instead of it looking like it disappeared.
 * Existing withdrawal/P2P fee rows are backfilled from the withdrawal_id they already had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deductions', function (Blueprint $t) {
            $t->string('type', 24)->default('withdrawal_fee')->after('withdrawal_id');
        });
        DB::table('deductions')->whereNull('withdrawal_id')->update(['type' => 'p2p_fee']);
    }

    public function down(): void
    {
        Schema::table('deductions', fn (Blueprint $t) => $t->dropColumn('type'));
    }
};
