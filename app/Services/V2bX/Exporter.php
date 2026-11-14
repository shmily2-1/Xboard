<?php

namespace App\Services\V2bX;

use App\Models\Server;
use App\Models\User;

class Exporter
{
    public static function node(Server $node, User $user): array
    {
        abort_unless($user->isAvailable() && in_array((string) $user->group_id, array_map('strval', $node->group_ids ?? []), true)
            && $node->show && $node->backend_profile === 'v2bx-extended', 403);
        abort_if($node->transfer_enable > 0 && $node->u + $node->d >= $node->transfer_enable, 403, '节点流量已用尽');
        $n = NodeConfig::build($node);
        $out = ['tag' => 'proxy', 'protocol' => $node->type,
            'settings' => ['address' => $node->host, 'port' => (int) $node->port]];
        if (in_array($node->type, ['http', 'mixed'], true)) {
            $out['protocol'] = $node->type === 'mixed' ? 'socks' : 'http';
            $out['settings'] += ['user' => $user->uuid, 'pass' => Credentials::secret($user->id)];
            if ($n['tls']) {
                $out['streamSettings'] = ['network' => 'tcp', 'security' => 'tls', 'tlsSettings' => [
                    'serverName' => $n['server_name'], 'alpn' => ['http/1.1']]];
            }
        } elseif ($node->type === 'masque') {
            abort_if($user->device_limit > 0, 422, 'MASQUE 暂不支持设备数量限制');
            $out['streamSettings'] = ['network' => 'masque', 'security' => 'tls',
                'tlsSettings' => ['serverName' => $n['server_name'], 'alpn' => $n['alpn'] ?? ['h3']],
                'masqueSettings' => ['host' => $n['masque_host'], 'path' => $n['path'],
                    'headers' => ['Authorization' => 'Basic ' . base64_encode($user->uuid . ':' . Credentials::secret($user->id))]]];
        } elseif ($node->type === 'vless' && $n['network'] === 'xdrive') {
            $x = $n['network_settings'];
            // Remote storage credentials require a separately isolated, explicitly provisioned profile.
            abort_unless(($x['service'] ?? '') === 'local', 422, '远端存储凭据暂不通过面板自动分发，请先配置隔离存储并验收');
            $encryption = data_get($node->protocol_settings, 'encryption.encryption');
            $e = $n['encryption_settings'];
            $public = [];
            foreach (explode('.', $e['private_key']) as $key) {
                $raw = base64_decode(strtr($key, '-_', '+/'), true);
                if (strlen($raw) !== 32) {
                    abort_unless($encryption && str_starts_with($encryption, 'mlkem768x25519plus.'), 422,
                        'ML-KEM 配置需要导入锁定 Xray 生成的客户端公钥参数');
                    $parts = explode('.', $encryption);
                    abort_unless(count($parts) >= 4 && $parts[1] === $e['mode'] && in_array($parts[2], ['0rtt', '1rtt'], true), 422, '客户端公钥参数格式无效');
                    foreach (array_slice($parts, 3) as $publicKey) {
                        $decoded = base64_decode(strtr($publicKey, '-_', '+/'), true);
                        abort_unless($decoded !== false && in_array(strlen($decoded), [32, 1184], true)
                            && !in_array($publicKey, explode('.', $e['private_key']), true), 422, '客户端公钥参数不能包含服务端私钥');
                    }
                    $public = [];
                    break;
                }
                $public[] = rtrim(strtr(base64_encode(sodium_crypto_scalarmult_base($raw)), '+/', '-_'), '=');
            }
            if ($public) { $encryption = implode('.', array_merge(['mlkem768x25519plus', $e['mode'], '0rtt'], $public)); }
            $out['settings'] = ['vnext' => [['address' => $node->host, 'port' => (int) $node->port,
                'users' => [['id' => $user->uuid, 'encryption' => $encryption]]]]];
            $out['mux'] = ['enabled' => true, 'concurrency' => 4];
            $out['streamSettings'] = ['network' => 'xdrive', 'xdriveSettings' => $x];
        } else { abort(422, '此节点请使用已有订阅格式'); }
        return ['outbounds' => [$out]];
    }
}
