<?php

namespace App\Console\Commands;

use App\Support\WindowsRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Utils\CacheKey;

class WindowsHealth extends Command
{
    protected $signature = 'xboard:windows-health {--runtime} {--json}';
    protected $description = 'Check Windows PHP, database, Redis, queues and scheduler without exposing secrets';

    public function handle(): int
    {
        $checks = [];
        foreach (['pdo_mysql', 'redis', 'mbstring', 'openssl', 'curl', 'zip', 'fileinfo', 'bcmath'] as $extension) {
            $checks['php_' . $extension] = extension_loaded($extension);
        }
        try {
            DB::select('SELECT 1');
            $checks['mysql'] = true;
            $checks['installed'] = DB::table('v2_user')->where('is_admin', 1)->exists();
        } catch (\Throwable $e) {
            $checks['mysql'] = false;
        }
        try {
            Redis::connection()->ping();
            $key = 'windows-health-' . bin2hex(random_bytes(8));
            Cache::put($key, 'ok', 30);
            $checks['redis_cache'] = Cache::get($key) === 'ok';
            Cache::forget($key);
        } catch (\Throwable $e) {
            $checks['redis_cache'] = false;
        }
        if ($this->option('runtime')) {
            $checks['queues'] = WindowsRuntime::healthy();
            $checks['scheduler'] = time() - (int) Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), 0) < 125;
        }
        $ok = !in_array(false, $checks, true);
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $ok, 'checks' => $checks, 'runtime' => WindowsRuntime::status()], JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $name => $pass) {
                $this->line(($pass ? '[OK]   ' : '[FAIL] ') . $name);
            }
        }
        return $ok ? 0 : 1;
    }
}
