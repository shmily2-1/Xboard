<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WindowsProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries = 1;
    public $timeout = 10;
    public int $sleepSeconds = 0;

    public function __construct(public string $probe, public string $label)
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $probe) || !preg_match('/^[a-z_]+$/', $label)) {
            throw new \InvalidArgumentException('Invalid local probe identifier');
        }
    }

    public function handle(): void
    {
        if ($this->sleepSeconds > 0) {
            sleep(min(30, $this->sleepSeconds));
        }
        $dir = storage_path('app/windows/probes/' . $this->probe);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create local probe directory');
        }
        file_put_contents($dir . '/' . $this->label . '.ok', (string) getmypid(), LOCK_EX);
    }
}
