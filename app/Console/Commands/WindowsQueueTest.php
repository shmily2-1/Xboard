<?php

namespace App\Console\Commands;

use App\Jobs\WindowsProbeJob;
use Illuminate\Console\Command;

class WindowsQueueTest extends Command
{
    protected $signature = 'xboard:windows-test';
    protected $description = 'Send harmless local test jobs through all nine application queues';

    public function handle(): int
    {
        $id = bin2hex(random_bytes(12));
        $queues = ['default','order_handle','traffic_fetch','stat','user_alive_sync','send_email','send_telegram','send_email_mass','node_sync'];
        $dir = storage_path('app/windows/probes/' . $id);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        foreach ($queues as $queue) {
            WindowsProbeJob::dispatch($id, $queue)->onConnection('redis')->onQueue($queue);
        }
        $dir = storage_path('app/windows/probes/' . $id);
        $end = time() + 45;
        do {
            $done = array_filter($queues, fn ($q) => is_file($dir . '/' . $q . '.ok'));
            if (count($done) === count($queues)) {
                break;
            }
            usleep(250000);
        } while (time() < $end);
        foreach ($queues as $queue) {
            $file = $dir . '/' . $queue . '.ok';
            $pass = is_file($file);
            $this->line(($pass ? '[OK] ' : '[FAIL] ') . $queue);
            if ($pass) {
                unlink($file);
            }
        }
        if (is_dir($dir) && count($done) === count($queues)) {
            rmdir($dir);
        }
        return count($done) === count($queues) ? 0 : 1;
    }
}
