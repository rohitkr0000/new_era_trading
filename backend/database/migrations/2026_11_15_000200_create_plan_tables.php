<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New Era Trading plan tables.
 * Every balance change is written to a ledger (income: `ledger`, purchase wallet: `wallet_ledger`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->json('value');
        });

        Schema::create('orders', function (Blueprint $t) {               // packages: joining + retopups
            $t->id();
            $t->string('ref', 20)->unique();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 18, 2);
            $t->string('kind', 10);                   // Joining | Retopup
            $t->string('option', 12);                 // Investment | Compounding
            $t->decimal('pct', 6, 3);                 // ROI % per trading day at purchase time
            $t->unsignedInteger('days')->default(0);  // max ROI days (0 = until cap)
            $t->unsignedInteger('steps')->default(0); // 24h steps already processed
            $t->unsignedInteger('paid_days')->default(0);
            $t->string('status', 10)->default('running')->index(); // running | closed | active
            $t->dateTime('created_at')->index();
        });

        Schema::create('ledger', function (Blueprint $t) {               // income wallet
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12)->index();          // roi | direct | retopup | level | withdrawal | refund | transfer | purchase
            $t->decimal('amount', 18, 2);             // + credit / − debit
            $t->string('note');
            $t->foreignId('from_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('created_at')->index();
            $t->index(['user_id', 'type']);
        });

        Schema::create('wallet_ledger', function (Blueprint $t) {        // purchase wallet
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 14);                   // deposit | purchase | transfer-in | transfer-out
            $t->decimal('amount', 18, 2);
            $t->decimal('balance', 18, 2);
            $t->string('note');
            $t->dateTime('created_at');
        });

        Schema::create('deposits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 18, 2);
            $t->string('txn_id', 120)->unique();
            $t->string('network', 60);
            $t->string('status', 10)->default('pending')->index();
            $t->dateTime('processed_at')->nullable();
            $t->dateTime('created_at');
        });

        Schema::create('withdrawals', function (Blueprint $t) {
            $t->id();
            $t->string('ref', 20)->unique();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 18, 2);
            $t->decimal('fee', 18, 2)->default(0);
            $t->decimal('net', 18, 2);
            $t->string('method', 30);
            $t->string('account', 160);
            $t->string('status', 10)->default('pending')->index();
            $t->dateTime('due_at')->nullable();
            $t->dateTime('processed_at')->nullable();
            $t->dateTime('created_at');
        });

        Schema::create('transfers', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 8);                    // self | member
            $t->foreignId('from_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('to_id')->constrained('users')->cascadeOnDelete();
            $t->decimal('amount', 18, 2);
            $t->dateTime('created_at');
        });

        Schema::create('deductions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('withdrawal_id')->nullable()->constrained()->nullOnDelete();
            $t->string('note');
            $t->decimal('amount', 18, 2);
            $t->string('status', 10)->default('Deducted');
            $t->dateTime('created_at');
        });

        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->string('ref', 20)->unique();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('subject', 160);
            $t->text('message');
            $t->string('status', 8)->default('open')->index();
            $t->dateTime('created_at');
        });

        Schema::create('ticket_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $t->string('by', 6);                      // user | admin
            $t->text('text');
            $t->dateTime('created_at');
        });

        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->text('text');
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        foreach (['announcements', 'ticket_replies', 'tickets', 'deductions', 'transfers', 'withdrawals', 'deposits', 'wallet_ledger', 'ledger', 'orders', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
