<?php

namespace App\Services\V2bX;

use App\Models\ManagedService;
use App\Models\ManagedPeer;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class Services
{
    public static function machineLock(int $id): ServerMachine
    {
        return ServerMachine::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public static function validateSpec(string $type, array $spec): void
    {
        $allowed = match ($type) {
            'tunnel' => ['Listen', 'Target', 'Network'],
            'tun' => ['TUN'],
            'wireguard' => ['Listen', 'WireGuard'],
            'amneziawg' => ['Listen', 'WireGuard', 'BackendProfile', 'Obfuscation'],
            'mtproto' => ['Listen', 'BackendProfile', 'Secret', 'PublicIP', 'Concurrency'],
            default => [],
        };
        if (!$allowed || array_diff(array_keys($spec), $allowed)) { NodeConfig::fail('服务类型或配置字段无效'); }
        Validator::make($spec, [
            'Listen' => 'sometimes|required|string|max:255', 'Target' => 'sometimes|required|string|max:255',
            'Network' => 'sometimes|required|string', 'Obfuscation' => 'sometimes|array',
            'Obfuscation.*' => 'required|string|max:4096', 'Secret' => 'sometimes|required|string|max:2048',
            'PublicIP' => 'sometimes|required|ip',
        ])->validate();
        if ($type !== 'tun') {
            $listen = $spec['Listen'] ?? '';
            if (!is_string($listen) || !preg_match('/^(?:\[[0-9a-fA-F:]+\]|[^:\s]+):([0-9]{1,5})$/D', $listen, $m)
                || (int) $m[1] < 1 || (int) $m[1] > 65535) { NodeConfig::fail('监听地址无效'); }
        }
        if ($type === 'tunnel') {
            if (!in_array($spec['Network'] ?? '', ['tcp', 'udp', 'tcp,udp'], true)
                || !preg_match('/^(?:\[[0-9a-fA-F:]+\]|[^:\s]+):[0-9]{1,5}$/D', $spec['Target'] ?? '')) {
                NodeConfig::fail('转发目标或网络无效');
            }
        }
        if ($type === 'tun') {
            Validator::make($spec, ['TUN' => 'required|array:Interface,Address,MTU,AutoRoute,RouteAddress,RouteExcludeAddress,OutboundInterface',
                'TUN.Interface' => 'required|string|max:15|regex:/^[a-zA-Z0-9_-]+$/',
                'TUN.Address' => 'required|array|min:1', 'TUN.MTU' => 'nullable|integer|min:1280|max:65535',
                'TUN.AutoRoute' => 'nullable|boolean', 'TUN.Address.*' => 'string',
                'TUN.RouteAddress' => 'nullable|array', 'TUN.RouteAddress.*' => 'string',
                'TUN.RouteExcludeAddress' => 'nullable|array', 'TUN.RouteExcludeAddress.*' => 'string',
                'TUN.OutboundInterface' => 'nullable|string|max:15|regex:/^[a-zA-Z0-9_-]+$/'])->validate();
            foreach (['Address', 'RouteAddress', 'RouteExcludeAddress'] as $key) {
                foreach ($spec['TUN'][$key] ?? [] as $cidr) { Network::prefix($cidr); }
            }
        }
        if (in_array($type, ['wireguard', 'amneziawg'], true)) {
            if ($type === 'wireguard' && !preg_match('/^(?:0\.0\.0\.0|\[::\]):[0-9]+$/D', $spec['Listen'])) {
                NodeConfig::fail('用户态 WireGuard 监听地址必须为 0.0.0.0 或 ::');
            }
            Validator::make($spec, ['WireGuard' => 'required|array:Address,MTU,Endpoint,DNS,ClientAllowedIPs',
                'WireGuard.Address' => 'required|array|min:1|max:2', 'WireGuard.Address.*' => 'required|string',
                'WireGuard.Endpoint' => ['required', 'string', 'max:255', 'regex:/^(?:\[[0-9a-fA-F:]+\]|[a-zA-Z0-9._-]+):[0-9]{1,5}$/D'], 'WireGuard.DNS' => 'nullable|array',
                'WireGuard.DNS.*' => 'ip', 'WireGuard.ClientAllowedIPs' => 'nullable|array',
                'WireGuard.ClientAllowedIPs.*' => 'string',
                'WireGuard.MTU' => 'nullable|integer|min:1280|max:65535'])->validate();
            $families = [];
            foreach ($spec['WireGuard']['Address'] as $cidr) {
                [$raw, $bits] = Network::prefix($cidr);
                if (isset($families[strlen($raw)]) || $bits >= strlen($raw) * 8 - 1) { NodeConfig::fail('地址池重复或空间过小'); }
                $families[strlen($raw)] = true;
            }
            foreach ($spec['WireGuard']['ClientAllowedIPs'] ?? [] as $cidr) { Network::prefix($cidr); }
        }
        if (in_array($type, ['amneziawg', 'mtproto'], true)) {
            Validator::make($spec, ['BackendProfile' => 'required|string|regex:/^[a-zA-Z0-9_-]{1,48}$/'])->validate();
        }
        if ($type === 'amneziawg') {
            foreach ($spec['Obfuscation'] ?? [] as $key => $value) {
                if (!in_array($key, ['jc','jmin','jmax','s1','s2','s3','s4','h1','h2','h3','h4','i1','i2','i3','i4','i5'], true)
                    || !is_string($value) || strlen($value) > 4096 || preg_match('/[\r\n\x00]/', $value)) { NodeConfig::fail('混淆参数无效'); }
            }
        }
        if ($type === 'mtproto') {
            if (!preg_match('/^ee[0-9a-fA-F]{32}(?:[0-9a-fA-F]{2})+$/D', $spec['Secret'] ?? '')
                || !filter_var($spec['PublicIP'] ?? '', FILTER_VALIDATE_IP)) { NodeConfig::fail('MTProto 需要 FakeTLS Secret 和有效公网 IP'); }
            Validator::make($spec, ['Concurrency' => 'nullable|integer|min:1|max:10000'])->validate();
        }
    }

    public static function key(string $key): bool
    {
        $raw = base64_decode($key, true);
        return $raw !== false && strlen($raw) === 32 && $raw !== str_repeat("\0", 32);
    }

    public static function putPeer(ManagedService $service, array $data): ManagedPeer
    {
        return DB::transaction(function () use ($service, $data) {
            self::machineLock($service->machine_id);
            $service = ManagedService::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if ($service->deleting || !in_array($service->type, ['wireguard', 'amneziawg'], true)) { NodeConfig::fail('服务不接受 Peer'); }
            $user = User::findOrFail($data['user_id']);
            if ($service->type === 'amneziawg' && $user->speed_limit > 0) { NodeConfig::fail('AmneziaWG 暂不支持用户限速'); }
            if (!self::key($data['public_key']) || (!empty($data['preshared_key']) && !self::key($data['preshared_key']))) {
                NodeConfig::fail('Peer 密钥无效');
            }
            $peer = ManagedPeer::firstOrNew(['service_id' => $service->id, 'user_id' => $user->id]);
            if ($peer->deleting) { NodeConfig::fail('Peer 正在撤销，请等待节点确认'); }
            $addresses = $peer->addresses;
            if (!$addresses) {
                $used = ManagedPeer::where('service_id', $service->id)->get()->flatMap(fn($p) => $p->addresses ?? [])->all();
                $addresses = [];
                foreach ($service->spec['WireGuard']['Address'] as $pool) {
                    $addresses[] = Network::allocate($pool, array_merge($used, $addresses));
                }
            }
            $peer->fill(['public_key' => $data['public_key'], 'addresses' => $addresses,
                'keepalive' => $data['keepalive'] ?? 25, 'enabled' => $data['enabled'] ?? true, 'retired' => false]);
            if (array_key_exists('preshared_key', $data)) { $peer->preshared_key = $data['preshared_key']; }
            $peer->save();
            $service->revision++;
            $service->state = 'pending';
            $service->save();
            return $peer;
        }, 3);
    }

    public static function desired(ManagedService $service): array
    {
        $spec = $service->spec;
        if (isset($spec['WireGuard'])) {
            $spec['WireGuard']['Peers'] = [];
            foreach (ManagedPeer::where('service_id', $service->id)->where('enabled', true)->where('deleting', false)->get() as $peer) {
                $user = User::find($peer->user_id);
                if (!$user || !$user->isAvailable()) { continue; }
                if ($service->type === 'amneziawg' && $user->speed_limit > 0) { NodeConfig::fail('AmneziaWG 用户限速不受支持'); }
                $spec['WireGuard']['Peers'][] = ['UID' => (int) $peer->user_id, 'PublicKey' => $peer->public_key,
                    'PreSharedKey' => $peer->preshared_key ?? '', 'Address' => $peer->addresses,
                    'KeepAlive' => (int) $peer->keepalive, 'SpeedLimit' => (int) $user->speed_limit];
            }
        }
        return ['id' => $service->id, 'revision' => (int) $service->revision, 'deleting' => $service->deleting,
            'spec' => ['ID' => 'xb-' . $service->id, 'Type' => $service->type,
                'Enabled' => $service->enabled && !$service->deleting, 'Revision' => (int) $service->revision] + $spec];
    }

    public static function publicSpec(array $spec): array
    {
        if (isset($spec['Secret'])) { $spec['Secret'] = '[REDACTED]'; }
        return $spec;
    }
}
