<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\ManagedPeer;
use App\Models\ManagedService;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerService;
use App\Services\V2bX\Credentials;
use App\Services\V2bX\Meter;
use App\Services\V2bX\Network;
use App\Services\V2bX\NodeConfig;
use App\Services\V2bX\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class V2bXController extends Controller
{
    public function nodes(Request $request)
    {
        $rows = ServerService::getAllServers()->filter(fn($s) => $s->backend_profile === 'v2bx-extended')
            ->filter(fn($s) => !$request->filled('q') || str_contains($s->name, $request->query('q')))
            ->map(function ($s) {
                $p = $s->protocol_settings;
                foreach (['private_key', 'decryption'] as $key) {
                    if (data_get($p, 'encryption.' . $key)) { $p['encryption'][$key] = '[REDACTED]'; }
                }
                if (isset($p['network_settings']['secrets'])) { $p['network_settings']['secrets'] = '[REDACTED]'; }
                // The editable configuration is only returned to administrators.
                return array_merge($s->toArray(), ['protocol_settings' => $p]);
            })->values();
        return $this->success($rows);
    }

    public function metadata()
    {
        return $this->success([
            'machines' => ServerMachine::get(['id', 'name']),
            'groups' => \App\Models\ServerGroup::get(['id', 'name']),
            'types' => ['http', 'mixed', 'masque', 'vless'],
            'service_types' => ['tunnel', 'tun', 'wireguard', 'amneziawg', 'mtproto'],
        ]);
    }

    public function nodeEdit(Request $request)
    {
        $node = Server::findOrFail($request->validate(['id' => 'required|integer'])['id']);
        abort_unless($node->backend_profile === 'v2bx-extended', 422);
        return $this->success($node);
    }

    public function services(Request $request)
    {
        $query = ManagedService::orderByDesc('id');
        if ($request->has('machine_id')) {
            $params = $request->validate(['machine_id' => 'required|integer|exists:v2_server_machine,id']);
            $query->where('machine_id', $params['machine_id']);
        }
        $rows = $query->get()->map(function ($s) {
            $row = $s->toArray();
            $row['spec'] = Services::publicSpec($s->spec);
            $row['peers'] = ManagedPeer::where('service_id', $s->id)->get();
            return $row;
        });
        return $this->success($rows);
    }

    public function serviceLink(Request $request)
    {
        $s = ManagedService::findOrFail($request->validate(['id' => 'required|integer'])['id']);
        abort_unless($s->type === 'mtproto' && $s->enabled && !$s->deleting && $s->state === 'running', 422, 'MTProto 服务尚未运行');
        $spec = $s->spec;
        preg_match('/:(\d+)$/', $spec['Listen'], $m);
        $link = 'tg://proxy?' . http_build_query(['server' => $spec['PublicIP'], 'port' => (int) $m[1], 'secret' => $spec['Secret']]);
        return response($link . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public function putService(Request $request)
    {
        $p = $request->validate(['id' => 'nullable|integer', 'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'name' => 'required|string|max:128', 'type' => 'required|in:tunnel,tun,wireguard,amneziawg,mtproto',
            'enabled' => 'required|boolean', 'spec' => 'required|array']);
        return DB::transaction(function () use ($p) {
            Services::machineLock($p['machine_id']);
            $s = isset($p['id']) ? ManagedService::whereKey($p['id'])->lockForUpdate()->firstOrFail() : new ManagedService;
            if ($s->deleting || ($s->exists && ((int) $s->machine_id !== (int) $p['machine_id'] || $s->type !== $p['type']))) {
                NodeConfig::fail('已有服务不能变更机器或类型，正在删除的服务不能编辑');
            }
            $spec = $p['spec'];
            if (($spec['Secret'] ?? '') === '[REDACTED]') { $spec['Secret'] = $s->spec['Secret'] ?? ''; }
            Services::validateSpec($p['type'], $spec);
            if ($s->exists && ManagedPeer::where('service_id', $s->id)->exists()
                && data_get($s->spec, 'WireGuard.Address') !== data_get($spec, 'WireGuard.Address')) {
                NodeConfig::fail('已有 Peer 的地址池不能直接变更，请先迁移 Peer');
            }
            foreach (ManagedService::where('machine_id', $p['machine_id'])->where('id', '!=', $s->id ?? 0)->get() as $other) {
                foreach (data_get($spec, 'WireGuard.Address', []) as $a) {
                    foreach (data_get($other->spec, 'WireGuard.Address', []) as $b) {
                        if ($other->state !== 'removed' && Network::overlaps($a, $b)) { NodeConfig::fail('同机器服务地址池不能重叠'); }
                    }
                }
            }
            $s->fill(['machine_id' => $p['machine_id'], 'name' => $p['name'], 'type' => $p['type'],
                'enabled' => $p['enabled'], 'spec' => $spec, 'state' => 'pending']);
            $s->revision = ($s->revision ?? 0) + 1;
            $s->save();
            return $this->success(['id' => $s->id, 'revision' => $s->revision]);
        }, 3);
    }

    public function serviceAction(Request $request)
    {
        $p = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer',
            'action' => 'required|in:enable,disable,restart,remove']);
        DB::transaction(function () use ($p) {
            foreach (ManagedService::whereIn('id', $p['ids'])->orderBy('machine_id')->get() as $s) {
                Services::machineLock($s->machine_id);
                $s->refresh();
                if ($s->deleting) { continue; }
                $s->enabled = in_array($p['action'], ['enable', 'restart'], true);
                $s->deleting = $p['action'] === 'remove';
                $s->state = 'pending';
                $s->revision++;
                $s->save();
            }
        }, 3);
        return $this->success(true);
    }

    public function putPeer(Request $request)
    {
        $p = $request->validate(['service_id' => 'required|integer', 'user_id' => 'required|integer|exists:v2_user,id',
            'public_key' => 'required|string|max:44', 'preshared_key' => 'nullable|string|max:44',
            'keepalive' => 'nullable|integer|min:0|max:65535', 'enabled' => 'nullable|boolean']);
        $peer = Services::putPeer(ManagedService::findOrFail($p['service_id']), $p);
        return $this->success($peer);
    }

    public function removePeer(Request $request)
    {
        $p = $request->validate(['id' => 'required|integer']);
        DB::transaction(function () use ($p) {
            $peer = ManagedPeer::findOrFail($p['id']);
            $s = ManagedService::findOrFail($peer->service_id);
            Services::machineLock($s->machine_id);
            $peer->update(['enabled' => false, 'deleting' => true]);
            $s->revision++;
            $s->state = 'pending';
            $s->save();
        }, 3);
        return $this->success(true);
    }

    public function rotate(Request $request)
    {
        $user = $request->user();
        if ($user->is_admin && $request->filled('user_id')) { $user = User::findOrFail($request->integer('user_id')); }
        Credentials::rotate($user);
        return $this->success(true);
    }

    private function machine(Request $request): ServerMachine
    {
        $p = $request->validate(['machine_id' => 'required|integer', 'token' => 'required|string']);
        $m = ServerMachine::whereKey($p['machine_id'])->where('is_active', true)->first();
        abort_unless($m && hash_equals($m->token, $p['token']), 403, '机器凭据无效');
        return $m;
    }

    public function pull(Request $request)
    {
        $m = $this->machine($request);
        $rows = ManagedService::where('machine_id', $m->id)->where('state', '!=', 'removed')->get()->map(function ($s) {
            $row = Services::desired($s);
            $row['hash'] = hash('sha256', json_encode($row['spec'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $row;
        });
        return response()->json(['contract' => 1, 'services' => $rows]);
    }

    public function ack(Request $request)
    {
        $m = $this->machine($request);
        $p = $request->validate(['service_id' => 'required|integer', 'revision' => 'required|integer|min:1',
            'hash' => 'required|string|size:64', 'state' => 'required|in:running,disabled,removed,error',
            'runtime' => 'present|array']);
        DB::transaction(function () use ($m, $p) {
            Services::machineLock($m->id);
            $s = ManagedService::whereKey($p['service_id'])->where('machine_id', $m->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode(Services::desired($s)['spec'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            abort_unless((int) $s->revision === $p['revision'] && hash_equals($hash, $p['hash']), 409, '配置已变化，请重新拉取');
            if ($p['state'] === 'removed' && !$s->deleting) { abort(409); }
            $runtime = array_intersect_key($p['runtime'], array_flip(['error', 'peers', 'process', 'server_public_key', 'backend_version', 'backend_sha256']));
            $s->runtime = $runtime + ['reported_at' => time()];
            $s->state = $p['state'];
            if ($p['state'] !== 'error') {
                $s->applied_revision = $p['revision'];
                // Keep revoked identities for delayed meter reports and reserve leases until applied.
                ManagedPeer::where('service_id', $s->id)->where('deleting', true)->update([
                    'enabled' => false, 'deleting' => false, 'retired' => true, 'addresses' => '[]']);
            }
            $s->save();
        }, 3);
        return $this->success(true);
    }

    public function serviceMeter(Request $request)
    {
        $m = $this->machine($request);
        $p = $request->validate(['service_id' => 'required|integer', 'batch_id' => 'required|string', 'traffic' => 'required|array']);
        DB::transaction(function () use ($m, $p) {
            Services::machineLock($m->id);
            $s = ManagedService::whereKey($p['service_id'])->where('machine_id', $m->id)->lockForUpdate()->firstOrFail();
            Meter::once('service:' . $s->id, $p['batch_id'], $p['traffic'], function ($rows) use ($s) {
                $u = $d = 0;
                foreach ($rows as $pair) { $u += $pair[0]; $d += $pair[1]; }
                if (in_array($s->type, ['wireguard', 'amneziawg'], true)) {
                    foreach ($rows as $uid => $pair) {
                        abort_unless(ManagedPeer::where('service_id', $s->id)->where('user_id', $uid)->exists(), 422, '未知 Peer');
                        ManagedPeer::where('service_id', $s->id)->where('user_id', $uid)->incrementEach(['u' => $pair[0], 'd' => $pair[1]]);
                    }
                    Meter::users($rows, 1, $s->type);
                } elseif (array_keys($rows) !== [0]) { NodeConfig::fail('该服务只支持服务总量计量'); }
                ManagedService::whereKey($s->id)->incrementEach(['u' => $u, 'd' => $d]);
            });
        }, 3);
        return response()->json(['ack' => $p['batch_id']]);
    }
}
