<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restores a file written by app:backup into the CURRENT database (all existing tables in the file are dropped first).
 * Use a spare database to test a backup:  DB_DATABASE=neweratrading_test php artisan app:restore <file> --force
 */
class RestoreCommand extends Command
{
    protected $signature = 'app:restore {file : path to a db-*.sql.gz file} {--force : do not ask for confirmation}';
    protected $description = 'Restore the database from an app:backup file (MySQL / MariaDB)';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) { $this->error("File not found: $file"); return self::FAILURE; }
        if (DB::getDriverName() !== 'mysql') { $this->error('Restore is supported on MySQL / MariaDB only.'); return self::FAILURE; }
        $db = DB::getDatabaseName();
        if (! $this->option('force') && ! $this->confirm("This REPLACES the tables of database \"$db\" with the backup. Continue?")) return self::FAILURE;

        $gz = gzopen($file, 'rb');
        $n = 0;
        $pdo = DB::getPdo();
        while (($line = gzgets($gz)) !== false) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--')) continue;
            $pdo->exec($line);
            $n++;
        }
        gzclose($gz);
        $this->info("Restored $n statements into \"$db\".");
        return self::SUCCESS;
    }
}
