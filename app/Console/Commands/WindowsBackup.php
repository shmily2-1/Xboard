<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class WindowsBackup extends Command
{
    protected $signature = 'xboard:windows-backup';
    protected $description = 'Create a private MySQL dump, configuration and application backup';

    public function handle(): int
    {
        $root = rtrim(config('windows.backup_root'), '/\\');
        if (!is_dir($root)) {
            $this->error('Private backup directory missing. Run Backup from the Windows manager first.');
            return 1;
        }
        $base = strtolower(str_replace('\\', '/', realpath(base_path())));
        $resolved = strtolower(str_replace('\\', '/', realpath($root)));
        if ($resolved === $base || str_starts_with($resolved, $base . '/')) {
            throw new \RuntimeException('Backups must be outside the website directory.');
        }
        $dir = $root . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        File::makeDirectory($dir, 0700, true);
        $ini = $dir . '/.mysql-client.cnf';
        try {
            $db = config('database.connections.mysql');
            $quote = fn ($v) => '"' . str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '\\r', '\\n'], (string) $v) . '"';
            $options = "[client]\r\n";
            foreach (['host', 'port', 'username', 'password'] as $key) {
                $options .= ($key === 'username' ? 'user' : $key) . '=' . $quote($db[$key]) . "\r\n";
            }
            file_put_contents($ini, $options, LOCK_EX);
            $process = new Process([
                config('windows.mysqldump'), '--defaults-extra-file=' . $ini,
                '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces',
                '--hex-blob', '--set-gtid-purged=OFF', '--default-character-set=utf8mb4',
                '--result-file=' . $dir . '/database.sql', $db['database'],
            ], base_path(), null, null, 300);
            $process->mustRun();
            if (!is_file($dir . '/database.sql') || filesize($dir . '/database.sql') < 100) {
                throw new \RuntimeException('Empty database dump');
            }
            // Environment stays separate from the code archive and inherits PRIVATE directory ACLs.
            copy(base_path('.env'), $dir . '/environment.env');
            $zip = new \ZipArchive();
            if ($zip->open($dir . '/application.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create application archive');
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path(), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                if ($relative === '.env' || str_starts_with($relative, '.git/') ||
                    str_starts_with($relative, 'storage/logs/') || str_starts_with($relative, 'storage/app/windows/') ||
                    str_starts_with($relative, 'storage/framework/') || str_starts_with($relative, 'bootstrap/cache/')) {
                    continue;
                }
                if (!$zip->addFile($file->getPathname(), $relative)) {
                    throw new \RuntimeException('Could not add backup file: ' . $relative);
                }
            }
            if (!$zip->close()) {
                throw new \RuntimeException('Could not finalize application archive');
            }
            $manifest = ['created_at' => date(DATE_ATOM), 'version' => config('windows.version'), 'files' => []];
            foreach (['database.sql', 'environment.env', 'application.zip'] as $name) {
                $manifest['files'][$name] = ['bytes' => filesize($dir . '/' . $name), 'sha256' => hash_file('sha256', $dir . '/' . $name)];
            }
            file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            file_put_contents($dir . '/COMPLETE', "Backup completed. Treat this directory as secret.\r\n");
            $this->info('Backup complete: ' . $dir);
            return 0;
        } catch (\Throwable $e) {
            // Keep incomplete artifacts for diagnosis; do not mark them COMPLETE.
            $this->error('Backup failed. Incomplete files were retained in ' . $dir);
            $this->error($e->getMessage());
            return 1;
        } finally {
            if (is_file($ini)) {
                unlink($ini);
            }
        }
    }
}
