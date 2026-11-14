<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\ManagedPeer;
use App\Models\ManagedService;
use App\Models\Server;
use App\Services\V2bX\Exporter;
use App\Services\V2bX\Services;
use Illuminate\Http\Request;

class V2bXExportController extends Controller
{
    public function connections(Request $request)
    {
        $u = $request->user();
        abort_unless($u->isAvailable(), 403, '套餐不可用');
        $nodes = Server::whereJsonContains('group_ids', (string) $u->group_id)->where('show', true)
            ->where('backend_profile', 'v2bx-extended')->get(['id', 'name', 'type']);
        $peers = ManagedPeer::where('user_id', $u->id)->where('enabled', true)->where('deleting', false)->get();
        $services = $peers->map(function ($p) {
            $s = ManagedService::find($p->service_id);
            if (!$s || !$s->enabled || $s->deleting) { return null; }
            return ['id' => $s->id, 'name' => $s->name, 'type' => $s->type,
                'addresses' => $p->addresses, 'public_key' => $p->public_key,
                'state' => $s->state, 'last_reported' => data_get($s->runtime, 'reported_at')];
        })->filter()->values();
        return $this->success(['nodes' => $nodes, 'services' => $services]);
    }

    public function export(Request $request)
    {
        $p = $request->validate(['kind' => 'required|in:node,service', 'id' => 'required|integer',
            'private_key' => 'nullable|string|max:44']);
        $u = $request->user();
        abort_unless($u->isAvailable(), 403, '套餐不可用');
        if ($p['kind'] === 'node') {
            $data = json_encode(Exporter::node(Server::findOrFail($p['id']), $u), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return response($data, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
        }
        $s = ManagedService::findOrFail($p['id']);
        abort_unless($s->enabled && !$s->deleting && $s->state === 'running', 422, '服务尚未应用或未运行');
        $peer = ManagedPeer::where('service_id', $s->id)->where('user_id', $u->id)
            ->where('enabled', true)->where('deleting', false)->firstOrFail();
        $private = $p['private_key'] ?? '';
        abort_unless(Services::key($private) && base64_encode(sodium_crypto_scalarmult_base(base64_decode($private))) === $peer->public_key,
            422, '客户端私钥与已登记公钥不匹配');
        $serverKey = data_get($s->runtime, 'server_public_key');
        abort_unless(is_string($serverKey) && Services::key($serverKey), 422, '节点尚未上报服务公钥');
        $w = $s->spec['WireGuard'];
        $lines = ['[Interface]', 'PrivateKey = ' . $private, 'Address = ' . implode(', ', $peer->addresses),
            'MTU = ' . ($w['MTU'] ?? ($s->type === 'wireguard' ? 1408 : 1420))];
        if ($w['DNS'] ?? []) { $lines[] = 'DNS = ' . implode(', ', $w['DNS']); }
        if ($s->type === 'amneziawg') {
            foreach ($s->spec['Obfuscation'] ?? [] as $key => $value) { $lines[] = ucfirst($key) . ' = ' . $value; }
        }
        $lines = array_merge($lines, ['', '[Peer]', 'PublicKey = ' . $serverKey, 'Endpoint = ' . $w['Endpoint']]);
        if ($peer->preshared_key) { $lines[] = 'PresharedKey = ' . $peer->preshared_key; }
        $lines[] = 'AllowedIPs = ' . implode(', ', $w['ClientAllowedIPs'] ?? array_map(
            fn($a) => str_contains($a, ':') ? '::/0' : '0.0.0.0/0', $w['Address']));
        $lines[] = 'PersistentKeepalive = ' . $peer->keepalive;
        return response(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
