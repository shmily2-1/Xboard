<?php

namespace App\Services\V2bX;

use App\Models\Server;
use Illuminate\Validation\ValidationException;

class NodeConfig
{
    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['protocol_settings' => $message]);
    }

    public static function encryption(array $settings): array
    {
        $enc = $settings['encryption'] ?? [];
        if (!($enc['enabled'] ?? false)) {
            return ['encryption' => 'none'];
        }
        $method = $enc['method'] ?? 'mlkem768x25519plus';
        $mode = $enc['mode'] ?? 'native';
        $ticket = $enc['ticket'] ?? '600s';
        $padding = $enc['server_padding'] ?? '';
        $private = $enc['private_key'] ?? '';
        if (!$private && ($enc['decryption'] ?? '')) {
            $parts = explode('.', $enc['decryption']);
            if (count($parts) < 4) {
                self::fail('VLESS 解密参数不完整');
            }
            [$method, $mode, $ticket] = array_splice($parts, 0, 3);
            $pads = [];
            $keys = [];
            foreach ($parts as $part) {
                if (strlen($part) < 20 && !$keys) {
                    $pads[] = $part;
                } else {
                    $keys[] = $part;
                }
            }
            $private = implode('.', $keys);
            $padding = implode('.', $pads);
        }
        if ($method !== 'mlkem768x25519plus' || !in_array($mode, ['native', 'xorpub', 'random'], true)
            || !preg_match('/^\d+(?:-\d+)?s?$/D', $ticket) || !$private) {
            self::fail('VLESS 加密方法、模式、票据或私钥无效');
        }
        foreach (explode('.', $private) as $key) {
            $raw = base64_decode(strtr($key, '-_', '+/'), true);
            if ($raw === false || !in_array(strlen($raw), [32, 64], true)) {
                self::fail('VLESS 私钥必须是 X25519 私钥或 ML-KEM seed');
            }
        }
        if ($padding && !preg_match('/^[0-9.-]+$/D', $padding)) {
            self::fail('VLESS 填充参数无效');
        }
        return ['encryption' => $method, 'encryption_settings' => [
            'mode' => $mode, 'ticket' => $ticket, 'server_padding' => $padding, 'private_key' => $private,
        ]];
    }

    public static function build(Server $node): array
    {
        if (!in_array($node->type, ['http', 'mixed', 'masque', 'vless', 'hysteria'], true)) {
            self::fail('扩展配置目前适用于 HTTP、Mixed、MASQUE、VLESS、Hysteria2');
        }
        $p = $node->protocol_settings;
        if ((float) $node->rate < 0 || (float) $node->rate > 1000) {
            self::fail('扩展节点流量倍率需在 0 到 1000 之间');
        }
        $out = [
            'protocol' => $node->type, 'host' => $node->host,
            'server_port' => (int) $node->server_port,
            'server_name' => data_get($p, 'tls_settings.server_name') ?: $node->host,
            'tls' => (int) ($p['tls'] ?? 0),
            'v2bx_contract' => 1, 'traffic_batch' => true,
        ];
        if (in_array($node->type, ['http', 'mixed'], true) && !in_array($out['tls'], [0, 1], true)) {
            self::fail('HTTP/Mixed TLS 模式只能为 0 或 1');
        }
        if ($node->type === 'masque') {
            if (\App\Models\User::whereIn('group_id', $node->group_ids ?? [])->where('device_limit', '>', 0)->exists()) {
                self::fail('MASQUE 关联权限组存在设备数量限制，当前节点适配不支持该限制');
            }
            if (empty($p['address']) || !str_starts_with($p['path'] ?? '', '/')) {
                self::fail('MASQUE 需要地址池及以 / 开头的路径');
            }
            foreach ($p['address'] as $cidr) {
                Network::prefix($cidr);
            }
            $out += array_intersect_key($p, array_flip(['address', 'mtu', 'path', 'masque_host', 'alpn']));
            $out['tls'] = 1;
        }
        if ($node->type === 'vless') {
            $networkSettings = $p['network_settings'] ?? [];
            // Xray transport settings are JSON objects. Older Xboard forms
            // persisted an empty array for TCP, which Xray rejects during
            // unmarshalling; normalize an empty value to {} at the API edge.
            if (is_array($networkSettings) && $networkSettings === []) {
                $networkSettings = (object) [];
            }
            $out += ['network' => $p['network'], 'network_settings' => $networkSettings,
                'tls_settings' => $out['tls'] === 2 ? $p['reality_settings'] : $p['tls_settings'],
                'flow' => $p['flow'] ?? ''];
            $out += self::encryption($p);
            if ($p['network'] === 'xdrive') {
                if ($out['tls'] !== 0 || $out['flow'] !== '' || $out['encryption'] === 'none') {
                    self::fail('XDRIVE 必须启用 VLESS Encryption、清空 flow 并关闭 TLS/REALITY');
                }
                $x = $p['network_settings'] ?? [];
                if (!in_array($x['service'] ?? '', ['local', 's3', 'google-drive', 'template'], true)
                    || !is_string($x['remoteFolder'] ?? null) || !$x['remoteFolder']) {
                    self::fail('XDRIVE 存储类型和远程目录不能为空');
                }
                foreach (['segmentBytes', 'flushIntervalMs', 'pollIntervalMs', 'maxPollIntervalMs',
                    'sessionTtlSeconds', 'concurrency', 'eagerWindowMs', 'holeTimeoutMs'] as $key) {
                    if (isset($x[$key]) && (!is_int($x[$key]) || $x[$key] < 0 || $x[$key] > 2147483647)) {
                        self::fail('XDRIVE 分段、轮询和并发参数必须是非负整数');
                    }
                }
            }
        }
        if ($node->type === 'hysteria') {
            $version = (int) ($p['version'] ?? 0);
            if ($version !== 2) {
                self::fail('V2bX 扩展后端目前只适配 Hysteria2（version=2）');
            }
            if ((int) ($p['tls'] ?? 1) !== 1) {
                self::fail('Hysteria2 必须启用 TLS');
            }
            $obfs = $p['obfs'] ?? [];
            $mode = $obfs['mode'] ?? ($obfs['type'] ?? '');
            if (!empty($obfs['open'])) {
                if (!in_array($mode, ['salamander', 'gecko'], true) || empty($obfs['password'])) {
                    self::fail('Hysteria2 伪装需要 Salamander/Gecko 类型和密码');
                }
                if ($mode === 'gecko') {
                    $min = (int) ($obfs['min_packet_size'] ?? 0);
                    $max = (int) ($obfs['max_packet_size'] ?? 0);
                    if ($min < 1 || $max < $min || $max > 2048) {
                        self::fail('Gecko 分片范围必须在 1 到 2048 之间');
                    }
                }
            } else {
                $mode = '';
            }
            $out += [
                'version' => 2,
                'tls_settings' => $p['tls'] ?? [],
                'up_mbps' => (int) data_get($p, 'bandwidth.up', 0),
                'down_mbps' => (int) data_get($p, 'bandwidth.down', 0),
                'obfs' => $mode ?: null,
                'obfs-password' => $obfs['password'] ?? null,
                'obfs-mode' => $mode ?: null,
                'obfs-min-packet-size' => $obfs['min_packet_size'] ?? null,
                'obfs-max-packet-size' => $obfs['max_packet_size'] ?? null,
                'quic' => $p['quic'] ?? null,
                'quic_initial_packet_size' => (int) data_get($p, 'quic.initial_packet_size', 0),
                'quic_disable_path_mtu_discovery' => (bool) data_get($p, 'quic.disable_path_mtu_discovery', false),
                'udp_idle_timeout' => (int) ($p['udp_idle_timeout'] ?? 60),
                'masquerade' => $p['masquerade'] ?? null,
            ];
        }
        if ($node->route_ids) {
            $out['routes'] = \App\Services\ServerService::getRoutes($node->route_ids);
        }
        return $out;
    }
}
