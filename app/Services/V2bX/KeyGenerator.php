<?php

namespace App\Services\V2bX;

use RuntimeException;

class KeyGenerator
{
    public static function generate(string $kind, string $core = 'xray'): array
    {
        if (!in_array($kind, ['x25519', 'vless_encryption', 'mlkem768', 'mldsa65', 'short_id', 'hy2_password', 'wireguard_psk'], true)) {
            throw new RuntimeException('不支持的生成类型');
        }

        if (in_array($kind, ['vless_encryption', 'mlkem768', 'mldsa65'], true) && $core !== 'xray') {
            throw new RuntimeException('ML-KEM、MLDSA65 和 VLESS Encryption 需要 Xray 内核');
        }

        return match ($kind) {
            'x25519' => self::x25519(),
            'short_id' => ['short_id' => bin2hex(random_bytes(8))],
            'hy2_password' => ['password' => self::randomText(24)],
            'wireguard_psk' => ['preshared_key' => base64_encode(random_bytes(32))],
            'vless_encryption', 'mlkem768', 'mldsa65' => self::xray($kind),
        };
    }

    private static function x25519(): array
    {
        $private = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        return [
            'private_key' => self::b64url($private),
            'public_key' => self::b64url(sodium_crypto_scalarmult_base($private)),
        ];
    }

    private static function xray(string $kind): array
    {
        $binary = (string) (env('V2BX_XRAY_BIN') ?: env('XRAY_BIN') ?: 'xray');
        $command = match ($kind) {
            'vless_encryption' => 'vlessenc',
            'mlkem768' => 'mlkem768',
            'mldsa65' => 'mldsa65',
        };
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(escapeshellarg($binary) . ' ' . $command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('无法启动 Xray 生成器，请配置 V2BX_XRAY_BIN');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException(trim($stderr) ?: 'Xray 生成器执行失败');
        }
        return self::parseXray($kind, $stdout);
    }

    private static function parseXray(string $kind, string $output): array
    {
        if ($kind === 'vless_encryption') {
            preg_match_all('/Authentication:\s*([^,\r\n]+).*?"decryption":\s*"([^"]+)".*?"encryption":\s*"([^"]+)"/s', $output, $matches, PREG_SET_ORDER);
            if (!$matches) {
                throw new RuntimeException('无法解析 Xray vlessenc 输出');
            }
            $selected = $matches[count($matches) > 1 ? 1 : 0];
            return ['authentication' => trim($selected[1]), 'decryption' => $selected[2], 'encryption' => $selected[3]];
        }
        $values = [];
        foreach (['Seed', 'Client', 'Hash32', 'Verify'] as $label) {
            if (preg_match('/^' . $label . ':\s*(\S+)/m', $output, $match)) {
                $values[strtolower($label)] = $match[1];
            }
        }
        if (!$values) {
            throw new RuntimeException('无法解析 Xray 密钥生成输出');
        }
        return $values;
    }

    private static function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function randomText(int $length): string
    {
        return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes($length))), 0, $length);
    }
}
