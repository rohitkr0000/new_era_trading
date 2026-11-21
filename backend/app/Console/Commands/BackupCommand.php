<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Database backup without needing mysqldump: writes a gzipped SQL file (DROP/CREATE/INSERT for every table)
 * plus a zip of the uploaded KYC documents, keeps the newest BACKUP_KEEP of each, and can copy them to a second location.
 *
 *   php artisan app:backup              (scheduled daily at 02:30)
 *   php artisan app:restore <file.sql.gz>
 */
class BackupCommand extends Command
{
    protected $signature = 'app:backup {--no-files : skip the KYC documents archive}';
    protected $description = 'Dump the database (and KYC documents) to storage/app/backups, prune old copies';

    public function handle(): int
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) { $this->error("Cannot create $dir"); return self::FAILURE; }

        $stamp = now()->format('Ymd-His');
        $sqlFile = "$dir/db-$stamp.sql.gz";
        try {
            $rows = $this->dump($sqlFile);
        } catch (\Throwable $e) {
            @unlink($sqlFile);
            $this->error('Backup failed: ' . $e->getMessage());
            report($e);
            return self::FAILURE;
        }
        $this->info(sprintf('Database: %s (%d rows, %s)', basename($sqlFile), $rows, $this->size($sqlFile)));
        $made = [$sqlFile];

        if (! $this->option('no-files') && class_exists(\ZipArchive::class)) {
            $kyc = storage_path('app/private/kyc');
            if (is_dir($kyc) && count(glob("$kyc/*")) > 0) {
                $zipFile = "$dir/kyc-$stamp.zip";
                $zip = new \ZipArchive();
                if ($zip->open($zipFile, \ZipArchive::CREATE) === true) {
                    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($kyc, \FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $f) if ($f->isFile()) $zip->addFile($f->getPathname(), substr($f->getPathname(), strlen($kyc) + 1));
                    $zip->close();
                    $made[] = $zipFile;
                    $this->info('KYC documents: ' . basename($zipFile) . ' (' . $this->size($zipFile) . ')');
                }
            }
        }

        if ($copy = config('app.backup_copy_path')) {
            if (! is_dir($copy)) @mkdir($copy, 0775, true);
            foreach ($made as $f) {
                if (is_dir($copy) && @copy($f, $copy . DIRECTORY_SEPARATOR . basename($f))) $this->info('Copied to ' . $copy);
                else $this->warn('Could not copy ' . basename($f) . ' to ' . $copy);
            }
        }

        $keep = max(1, (int) config('app.backup_keep'));
        foreach (['db-*.sql.gz', 'kyc-*.zip'] as $pattern) {
            $files = glob("$dir/$pattern") ?: [];
            rsort($files);
            foreach (array_slice($files, $keep) as $old) { @unlink($old); $this->line('Pruned ' . basename($old)); }
        }
        cache()->forever('net.backup.last', now()->toIso8601String());
        return self::SUCCESS;
    }

    private function size(string $f): string { return round(filesize($f) / 1024, 1) . ' KB'; }

    /** @return int number of rows written */
    private function dump(string $file): int
    {
        $pdo = DB::getPdo();
        $driver = DB::getDriverName();
        $gz = gzopen($file, 'wb9');
        if (! $gz) throw new \RuntimeException('Cannot write ' . $file);
        $w = fn (string $line) => gzwrite($gz, $line . "\n");
        $total = 0;

        $w('-- New Era Trading backup ' . now()->toDateTimeString() . ' (' . $driver . ')');
        if ($driver === 'mysql') { $w('SET NAMES utf8mb4;'); $w('SET FOREIGN_KEY_CHECKS=0;'); $w("SET sql_mode='NO_AUTO_VALUE_ON_ZERO';"); }

        $tables = $driver === 'mysql'
            ? array_map(fn ($r) => array_values((array) $r)[0], DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            : array_map(fn ($r) => $r->name, DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"));

        foreach ($tables as $t) {
            if ($driver === 'mysql') {
                $create = (array) DB::selectOne('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`');
                $ddl = $create['Create Table'] ?? array_values($create)[1];
                $w('DROP TABLE IF EXISTS `' . $t . '`;');
                $w(preg_replace('/\s*\n\s*/', ' ', $ddl) . ';');
            } else {
                $w('DROP TABLE IF EXISTS "' . $t . '";');
                $w(preg_replace('/\s*\n\s*/', ' ', DB::selectOne("SELECT sql FROM sqlite_master WHERE name = ?", [$t])->sql) . ';');
            }

            $cols = null;
            $batch = [];
            $flush = function () use (&$batch, &$cols, $w, $driver, $t) {
                if (! $batch) return;
                $q = $driver === 'mysql' ? '`' : '"';
                $w('INSERT INTO ' . $q . $t . $q . ' (' . $q . implode($q . ', ' . $q, $cols) . $q . ') VALUES ' . implode(', ', $batch) . ';');
                $batch = [];
            };
            foreach (DB::table($t)->cursor() as $row) {
                $row = (array) $row;
                $cols ??= array_keys($row);
                $batch[] = '(' . implode(', ', array_map(function ($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    if (is_int($v) || is_float($v)) return (string) $v;
                    return $pdo->quote((string) $v);
                }, $row)) . ')';
                $total++;
                if (count($batch) >= 100) $flush();
            }
            $flush();
        }
        if ($driver === 'mysql') $w('SET FOREIGN_KEY_CHECKS=1;');
        $w('-- end of backup');
        gzclose($gz);
        return $total;
    }
}
