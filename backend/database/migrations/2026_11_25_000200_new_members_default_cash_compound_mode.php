<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Only changes the column's DEFAULT for rows created from now on — existing members keep whatever
 * compound_mode they already have (almost all still 'compound', the old default). A brand-new member
 * who never touches the Compound / Transfer-to-income choice now starts on 'cash' (flat daily payout),
 * matching the old Simple-interest package's behaviour, instead of silently auto-compounding.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            // SQLite has no ALTER COLUMN DEFAULT; nothing to do for the test DB (new rows there are inserted
            // without relying on the column default anyway — tests set compound_mode explicitly when needed).
            return;
        }
        DB::statement("ALTER TABLE users ALTER COLUMN compound_mode SET DEFAULT 'cash'");
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') return;
        DB::statement("ALTER TABLE users ALTER COLUMN compound_mode SET DEFAULT 'compound'");
    }
};
