<?php

use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * · users.phone_key — normalised mobile number (last 10 digits) with a UNIQUE index: one account per mobile number.
 * · otps — one-time passwords sent by email (password change …), stored hashed with expiry and attempt limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_key', 20)->nullable()->unique()->after('phone');
        });

        // backfill for existing rows (first owner of a number keeps it)
        $seen = [];
        foreach (DB::table('users')->whereNotNull('phone')->get(['id', 'phone']) as $u) {
            $key = Phone::key($u->phone);
            if ($key && ! isset($seen[$key])) { $seen[$key] = true; DB::table('users')->where('id', $u->id)->update(['phone_key' => $key]); }
        }

        Schema::create('otps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('purpose', 20);
            $t->string('code_hash');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->dateTime('expires_at');
            $t->dateTime('created_at');
            $t->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otps');
        Schema::table('users', function (Blueprint $table) { $table->dropColumn('phone_key'); });
    }
};
