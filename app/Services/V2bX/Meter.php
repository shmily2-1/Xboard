<?php

namespace App\Services\V2bX;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Meter
{
    public static function validate(array $traffic): array
    {
        if (count($traffic) > 5000) { NodeConfig::fail('单批流量最多 5000 条'); }
        foreach ($traffic as $uid => $pair) {
            if (!preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $uid) || (int) $uid < 0 || !is_array($pair) || array_keys($pair) !== [0, 1]) {
                NodeConfig::fail('流量格式无效');
            }
            foreach ($pair as $value) {
                if (!is_int($value) || $value < 0 || $value > 1000000000000000) {
                    NodeConfig::fail('流量字节数必须是非负整数');
                }
            }
        }
        ksort($traffic, SORT_NUMERIC);
        return $traffic;
    }

    public static function once(string $source, string $batch, array $traffic, callable $apply): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $batch)) { NodeConfig::fail('流量批次号无效'); }
        $traffic = self::validate($traffic);
        $digest = hash('sha256', json_encode($traffic));
        // The caller locks its authenticated resource within the same transaction.
        $existing = DB::table('v2_meter_batch')->where(compact('source'))->where('batch_id', $batch)->first();
        if ($existing) {
            if (!hash_equals($existing->digest, $digest)) { abort(409, '批次号对应的内容已改变'); }
            return;
        }
        $apply($traffic);
        DB::table('v2_meter_batch')->insert(['source' => $source, 'batch_id' => $batch,
            'digest' => $digest, 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function users(array $traffic, float $rate, string $protocol): void
    {
        foreach ($traffic as $uid => $pair) {
            User::whereKey($uid)->incrementEach(['u' => (int) ($pair[0] * $rate),
                'd' => (int) ($pair[1] * $rate)], ['t' => time()]);
        }
        if ($traffic) { (new StatUserJob(['rate' => $rate], $traffic, $protocol))->handle(); }
    }

    public static function node(Server $node, string $batch, array $traffic): void
    {
        DB::transaction(function () use ($node, $batch, $traffic) {
            $locked = Server::whereKey($node->id)->lockForUpdate()->firstOrFail();
            self::once('node:' . $locked->id, $batch, $traffic, function ($rows) use ($locked) {
                if (array_key_exists(0, $rows)) { NodeConfig::fail('代理节点不能按未知用户计费'); }
                $count = User::whereIn('id', array_keys($rows))->whereIn('group_id', $locked->group_ids ?? [])->count();
                if ($count !== count($rows)) { NodeConfig::fail('流量包含不属于节点权限组的用户'); }
                $rate = $locked->getCurrentRate();
                self::users($rows, $rate, $locked->type);
                (new StatServerJob($locked->toArray(), $rows, $locked->type))->handle();
            });
        }, 3);
    }
}
