<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member fields on top of Laravel's users table.
 * Money columns are DECIMAL(18,2) — never floats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('id');        // NET1001 …
            $table->string('role', 10)->default('member')->index()->after('code'); // member | admin
            $table->string('phone', 30)->nullable();
            $table->string('country', 60)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('usdt_address', 120)->nullable();
            $table->string('txn_pin')->nullable();                                 // bcrypt hash
            $table->boolean('blocked')->default(false);

            // sponsor (referral) line — drives direct / retopup / 12-level income
            $table->foreignId('sponsor_id')->nullable()->index()->constrained('users')->nullOnDelete();
            // binary placement — genealogy structure only
            $table->foreignId('parent_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('leg', 5)->nullable();
            $table->foreignId('left_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('right_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('left_bv', 18, 2)->default(0);
            $table->decimal('right_bv', 18, 2)->default(0);

            $table->decimal('invested', 18, 2)->default(0);        // total of all packages
            $table->decimal('wallet', 18, 2)->default(0);          // income wallet
            $table->decimal('purchase_wallet', 18, 2)->default(0); // funded by deposits / transfers
            $table->dateTime('joined_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['sponsor_id', 'parent_id', 'left_id', 'right_id'] as $fk) $table->dropConstrainedForeignId($fk);
            $table->dropColumn(['code', 'role', 'phone', 'country', 'city', 'usdt_address', 'txn_pin', 'blocked', 'leg',
                'left_bv', 'right_bv', 'invested', 'wallet', 'purchase_wallet', 'joined_at']);
        });
    }
};
