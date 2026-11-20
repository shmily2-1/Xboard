<?php

namespace App\Support;

class WindowsRuntime
{
    public static function status(): array
    {
        $path = storage_path('app/windows/supervisor.json');
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        if (!is_array($data) || time() - ($data['timestamp'] ?? 0) > 20) {
            return ['running' => false, 'workers' => [], 'pid' => null];
        }
        return $data;
    }

    public static function healthy(): bool
    {
        $state = self::status();
        $workers = $state['workers'] ?? [];
        return ($state['running'] ?? false) && count($workers) === 3
            && count(array_filter($workers, fn ($w) => $w['healthy'] ?? false)) === 3;
    }

    public static function processes(): int
    {
        return count(array_filter(self::status()['workers'] ?? [], fn ($w) => $w['healthy'] ?? false));
    }

    public static function workload(): array
    {
        $queue = app(\Illuminate\Contracts\Queue\Factory::class)->connection('redis');
        $calculator = app(\Laravel\Horizon\WaitTimeCalculator::class);
        $result = [];
        foreach (self::status()['workers'] ?? [] as $worker) {
            $count = ($worker['healthy'] ?? false) ? 1 : 0;
            $split = [];
            $wait = 0;
            foreach (explode(',', $worker['queue']) as $name) {
                $wait += $calculator->calculateTimeToClear('redis', $name, max(1, $count));
                $split[] = ['name' => $name, 'length' => $queue->readyNow($name), 'wait' => $wait];
            }
            $result[] = ['name' => $worker['queue'], 'length' => array_sum(array_column($split, 'length')),
                'wait' => $wait, 'processes' => $count, 'split_queues' => $split];
        }
        return $result;
    }

    public static function workerBeat(string $state, ?object $job = null): void
    {
        $dir = storage_path('app/windows');
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $timeout = $job ? (int) ($job->timeout() ?: 60) : 0;
        $data = ['pid' => getmypid(), 'timestamp' => time(), 'state' => $state,
            'started' => $job ? microtime(true) : null,
            // Always kill before Redis retries the reserved job (180 seconds).
            'deadline_seconds' => $job ? min(120, max(1, $timeout) + 5) : null];
        file_put_contents($dir . '/worker-' . getmypid() . '.json', json_encode($data), LOCK_EX);
    }
}
