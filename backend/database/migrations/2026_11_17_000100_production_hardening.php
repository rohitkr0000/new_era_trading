<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Go-live features: audit log, KYC, deposit verification on the blockchain, payout hash on withdrawals,
 * last-login tracking. (E-mail verification reuses Laravel's users.email_verified_at.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('kyc_status', 10)->default('none')->index();   // none | pending | approved | rejected
            $t->string('kyc_note', 255)->nullable();
            $t->dateTime('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
        });

        Schema::create('kyc_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('doc_type', 30);
            $t->string('doc_number', 60);
            $t->string('front_path');
            $t->string('back_path')->nullable();
            $t->string('selfie_path');
            $t->string('status', 10)->default('pending')->index();
            $t->string('note', 255)->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('reviewed_at')->nullable();
            $t->dateTime('created_at')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->index();            // no FK: the log must outlive users
            $t->string('actor', 160)->nullable();                      // "Name <email>" at the time
            $t->string('action', 60)->index();
            $t->string('target', 80)->nullable();
            $t->text('meta')->nullable();                              // JSON
            $t->string('ip', 45)->nullable();
            $t->dateTime('created_at')->nullable()->index();
        });

        Schema::table('deposits', function (Blueprint $t) {
            $t->string('verified', 12)->nullable();                    // verified | mismatch | failed | unchecked
            $t->string('verify_note', 255)->nullable();
            $t->decimal('chain_amount', 18, 2)->nullable();            // what the blockchain says arrived
        });

        Schema::table('withdrawals', function (Blueprint $t) {
            $t->string('payout_txid', 120)->nullable();               // hash of the on-chain payout the admin sent
            $t->string('admin_note', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', fn (Blueprint $t) => $t->dropColumn(['payout_txid', 'admin_note']));
        Schema::table('deposits', function (Blueprint $t) {
            $t->dropColumn(['verified', 'verify_note', 'chain_amount']);
        });
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('kyc_submissions');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['kyc_status', 'kyc_note', 'last_login_at', 'last_login_ip']));
    }
};
