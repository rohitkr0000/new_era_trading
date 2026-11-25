<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compounding packages now mature per order instead of rolling forever: when a package's principal
 * finishes its 25-day cycle, it freezes (status "matured") instead of silently losing the principal's
 * value, and the frozen total is held here until the member withdraws it or starts a new cycle on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('matured_balance', 18, 2)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('matured_balance'));
    }
};
