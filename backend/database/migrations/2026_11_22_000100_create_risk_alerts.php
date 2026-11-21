<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Suspicious-activity / data-error alerts raised by App\Services\RiskService and handled by admins. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('risk_alerts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();   // the member the alert is about (for group alerts: one of them, the rest are in `data`)
            $t->string('code', 40)->index();                          // rule, e.g. balance-mismatch
            $t->string('severity', 8)->index();                       // high | medium | low
            $t->string('title', 160);
            $t->text('detail');
            $t->text('data')->nullable();                             // JSON: extra facts (member codes, amounts …)
            $t->string('fingerprint', 64);                            // same problem = same fingerprint → raised once, a changed problem raises again
            $t->string('status', 10)->default('open')->index();       // open | resolved | dismissed | cleared (condition went away by itself)
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->dateTime('handled_at')->nullable();
            $t->string('note', 255)->nullable();
            $t->dateTime('created_at')->nullable()->index();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['user_id', 'code', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_alerts');
    }
};
