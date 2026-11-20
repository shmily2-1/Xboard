<?php

namespace App\Providers;

use App\Support\WindowsRuntime;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class WindowsRuntimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (!config('windows.enabled') || !$this->app->runningInConsole()) {
            return;
        }
        $idle = function () {
            WindowsRuntime::workerBeat('idle');
            if (is_file(storage_path('app/windows/stop.flag'))) {
                $this->app['queue.worker']->shouldQuit = true;
                return false;
            }
            // --force allows this heartbeat to run while Laravel is in maintenance.
            // Returning false still pauses job execution, without losing stop signals.
            if ($this->app->isDownForMaintenance()) {
                return false;
            }
        };
        Queue::looping($idle);
        Queue::before(fn (JobProcessing $event) => WindowsRuntime::workerBeat('busy', $event->job));
        Queue::after($idle);
        Queue::exceptionOccurred($idle);
    }
}
