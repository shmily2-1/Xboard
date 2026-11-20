<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;
use App\Helpers\ResponseEnum;

class SystemController extends Controller
{
    public function getSystemStatus()
    {
        $data = [
            'runtime' => config('windows.enabled') ? 'windows-native' : 'horizon',
            'schedule' => $this->getScheduleStatus(),
            'horizon' => $this->getHorizonStatus(),
            'schedule_last_runtime' => Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null)),
        ];
        return $this->success($data);
    }

    public function getQueueWorkload(WorkloadRepository $workload)
    {
        if (config('windows.enabled')) {
            return $this->success(\App\Support\WindowsRuntime::workload());
        }
        return $this->success(collect($workload->get())->sortBy('name')->values()->toArray());
    }

    protected function getScheduleStatus(): bool
    {
        return (time() - 120) < Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
    }

    protected function getHorizonStatus(): bool
    {
        if (config('windows.enabled')) {
            return \App\Support\WindowsRuntime::healthy();
        }
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return false;
        }

        return collect($masters)->contains(function ($master) {
            return $master->status === 'paused';
        }) ? false : true;
    }

    public function getQueueStats()
    {
        $data = [
            'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
            'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
            'pausedMasters' => $this->totalPausedMasters(),
            'periods' => [
                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                'recentJobs' => config('horizon.trim.recent'),
            ],
            'processes' => $this->totalProcessCount(),
            'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
            'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
            'recentJobs' => app(JobRepository::class)->countRecent(),
            'status' => $this->getHorizonStatus(),
            'wait' => config('windows.enabled')
                ? collect(\App\Support\WindowsRuntime::workload())->mapWithKeys(fn ($w) => ['redis:' . $w['name'] => $w['wait']])->sortDesc()->take(1)
                : collect(app(WaitTimeCalculator::class)->calculate())->take(1),
        ];
        return $this->success($data);
    }

    /**
     * Get the total process count across all supervisors.
     *
     * @return int
     */
    protected function totalProcessCount()
    {
        if (config('windows.enabled')) {
            return \App\Support\WindowsRuntime::processes();
        }
        $supervisors = app(SupervisorRepository::class)->all();

        return collect($supervisors)->reduce(function ($carry, $supervisor) {
            return $carry + collect($supervisor->processes)->sum();
        }, 0);
    }

    /**
     * Get the number of master supervisors that are currently paused.
     *
     * @return int
     */
    protected function totalPausedMasters()
    {
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return 0;
        }

        return collect($masters)->filter(function ($master) {
            return $master->status === 'paused';
        })->count();
    }

    public function getQueueMasters()
    {
        if (!config('windows.enabled')) {
            return app()->call([app(\Laravel\Horizon\Http\Controllers\MasterSupervisorController::class), 'index']);
        }
        $state = \App\Support\WindowsRuntime::status();
        if (!($state['running'] ?? false)) {
            return response()->json((object) []);
        }
        $name = 'windows-native';
        $supervisors = array_map(fn ($w) => [
            'name' => $name . ':' . $w['name'], 'master' => $name,
            'status' => ($w['healthy'] ?? false) ? 'running' : 'inactive',
            'pid' => $w['pid'], 'processes' => [$w['queue'] => ($w['healthy'] ? 1 : 0)],
            'options' => ['queue' => $w['queue'], 'balance' => false],
        ], $state['workers'] ?? []);
        return response()->json([$name => [
            'name' => $name, 'pid' => $state['pid'], 'status' => 'running',
            'environment' => config('app.env'), 'supervisors' => $supervisors,
        ]]);
    }

    public function getAuditLog(Request $request)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(10, (int) $request->input('page_size', 10));

        $builder = AdminAuditLog::with('admin:id,email')
            ->orderBy('id', 'DESC')
            ->when($request->input('action'), fn($q, $v) => $q->where('action', $v))
            ->when($request->input('admin_id'), fn($q, $v) => $q->where('admin_id', $v))
            ->when($request->input('keyword'), function ($q, $keyword) {
                $q->where(function ($q) use ($keyword) {
                    $q->where('uri', 'like', '%' . $keyword . '%')
                      ->orWhere('request_data', 'like', '%' . $keyword . '%');
                });
            });

        $total = $builder->count();
        $res = $builder->forPage($current, $pageSize)->get();

        return response(['data' => $res, 'total' => $total]);
    }

    public function getHorizonFailedJobs(Request $request, JobRepository $jobRepository)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(10, (int) $request->input('page_size', 20));
        $offset = ($current - 1) * $pageSize;

        $failedJobs = collect($jobRepository->getFailed())
            ->sortByDesc('failed_at')
            ->slice($offset, $pageSize)
            ->values();

        $total = $jobRepository->countFailed();

        return response()->json([
            'data' => $failedJobs,
            'total' => $total,
            'current' => $current,
            'page_size' => $pageSize,
        ]);
    }

}
