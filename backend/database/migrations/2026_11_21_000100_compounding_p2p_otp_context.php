<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * · Automatic compounding (company spreadsheet): every amount that earns is a "lot" that earns the daily rate for 25 trading days
 *   and then expires. In compounding mode each day's interest becomes a new lot, in cash mode it is paid to the income wallet.
 * · P2P transfers carry a company charge (fee / net).
 * · An e-mailed OTP is bound to the exact request it was issued for (otps.context): a code for "send $5 to NET1002"
 *   cannot be reused to send $500 to somebody else.
 * · Extra indexes for the dashboards and the daily jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('compound_mode', 8)->default('compound');       // compound | cash — what happens to the daily interest of the compounding balance
            $t->date('compound_through')->nullable();                  // last calendar day (settings timezone) already processed
        });

        Schema::create('compound_lots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 18, 2);
            $t->boolean('is_principal')->default(false);               // the purchased amount itself (its expiry closes the package)
            $t->date('created_on');                                    // earns from the NEXT day on
            $t->unsignedSmallInteger('days_left');                     // earning days remaining; 0 = expired
            $t->index(['user_id', 'days_left']);
        });

        Schema::create('compound_ledger', function (Blueprint $t) {    // the daily schedule shown to the member (like the spreadsheet)
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->string('mode', 8);
            $t->decimal('opening', 18, 2);
            $t->decimal('interest', 18, 2);
            $t->decimal('expiring', 18, 2)->default(0);
            $t->decimal('closing', 18, 2);
            $t->unique(['user_id', 'day']);                            // makes the daily job idempotent
        });

        Schema::table('transfers', function (Blueprint $t) {
            $t->decimal('fee', 18, 2)->default(0);
            $t->decimal('net', 18, 2)->nullable();
        });

        Schema::table('otps', function (Blueprint $t) {
            $t->string('context', 64)->nullable();
        });

        Schema::table('ledger', fn (Blueprint $t) => $t->index(['user_id', 'created_at']));
        Schema::table('orders', fn (Blueprint $t) => $t->index(['option', 'status']));
        Schema::table('transfers', function (Blueprint $t) { $t->index('created_at'); });
        Schema::table('deposits', fn (Blueprint $t) => $t->index('created_at'));
        Schema::table('withdrawals', fn (Blueprint $t) => $t->index('created_at'));
    }

    public function down(): void
    {
        Schema::table('withdrawals', fn (Blueprint $t) => $t->dropIndex(['created_at']));
        Schema::table('deposits', fn (Blueprint $t) => $t->dropIndex(['created_at']));
        Schema::table('transfers', fn (Blueprint $t) => $t->dropIndex(['created_at']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropIndex(['option', 'status']));
        Schema::table('ledger', fn (Blueprint $t) => $t->dropIndex(['user_id', 'created_at']));
        Schema::table('otps', fn (Blueprint $t) => $t->dropColumn('context'));
        Schema::table('transfers', fn (Blueprint $t) => $t->dropColumn(['fee', 'net']));
        Schema::dropIfExists('compound_ledger');
        Schema::dropIfExists('compound_lots');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['compound_mode', 'compound_through']));
    }
};
