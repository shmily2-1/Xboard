<?php

namespace App\Services\V2bX;

class Network
{
    public static function prefix(string $cidr): array
    {
        $parts = explode('/', $cidr);
        $raw = inet_pton($parts[0]);
        if (count($parts) !== 2 || $raw === false || !ctype_digit($parts[1])
            || (int) $parts[1] > strlen($raw) * 8) {
            NodeConfig::fail('地址池 CIDR 无效');
        }
        $bits = (int) $parts[1];
        $mask = '';
        for ($i = 0; $i < strlen($raw); $i++) {
            $n = max(0, min(8, $bits - $i * 8));
            $mask .= chr($n === 0 ? 0 : (255 << (8 - $n)) & 255);
        }
        return [$raw, $bits, $raw & $mask, $mask];
    }

    public static function overlaps(string $a, string $b): bool
    {
        [$ar, $ab, $an, $am] = self::prefix($a);
        [$br, $bb, $bn, $bm] = self::prefix($b);
        return strlen($ar) === strlen($br) && ($ab <= $bb ? ($br & $am) === $an : ($ar & $bm) === $bn);
    }

    public static function allocate(string $pool, array $used): string
    {
        [$server, $bits, $raw, $mask] = self::prefix($pool);
        if ($bits >= strlen($raw) * 8 - 1) {
            NodeConfig::fail('地址池空间过小');
        }
        for ($n = 0; $n < 65536; $n++) {
            for ($i = strlen($raw) - 1; $i >= 0; $i--) {
                $v = (ord($raw[$i]) + 1) & 255;
                $raw[$i] = chr($v);
                if ($v) { break; }
            }
            if (($raw & $mask) !== (inet_pton(explode('/', $pool)[0]) & $mask)) { break; }
            $host = inet_ntop($raw);
            $address = $host . '/' . strlen($raw) * 8;
            $broadcast = strlen($raw) === 4 && $raw === (($server & $mask) | ~$mask);
            if ($raw !== $server && !$broadcast && !in_array($address, $used, true)) {
                return $address;
            }
        }
        NodeConfig::fail('地址池已耗尽');
    }
}
