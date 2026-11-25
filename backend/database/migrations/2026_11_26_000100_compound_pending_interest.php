<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily compounding interest no longer auto-routes anywhere (no more global "compound vs cash" mode). It
 * accumulates per ORDER in orders.pending_interest until the member decides, per package, whether to transfer
 * it to the income wallet or fold it into that package's principal (CompoundService::decideInterest()).
 * users.compound_mode is retired along with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('pending_interest', 18, 2)->default(0)->after('matured_balance');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('compound_mode');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('pending_interest'));
        Schema::table('users', function (Blueprint $t) {
            $t->string('compound_mode', 8)->default('compound');
        });
    }
};
