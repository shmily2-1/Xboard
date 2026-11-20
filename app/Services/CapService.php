<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Local Cap integration. End users can never choose an upstream URL or see a service secret. */
class CapService
{
    public function publicConfiguration(): array
    {
        try {
            $config = $this->configuration();
            return ['cap_site_key' => $config['siteKey'], 'cap_api_endpoint' => '/api/v1/guest/captcha/', 'cap_installed' => true];
        } catch (\Throwable $e) {
            return ['cap_site_key' => null, 'cap_api_endpoint' => '/api/v1/guest/captcha/', 'cap_installed' => false];
        }
    }

    protected function configuration(): array
    {
        $path = base_path('windows/cap/private.json');
        if (!is_file($path)) throw new RuntimeException('Cap is not installed');
        $config = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $port = $config['port'] ?? null;
        if (!is_int($port) || $port < 1024 || $port > 65535 ||
            !preg_match('/^[a-zA-Z0-9_-]{8,64}$/D', $config['siteKey'] ?? '') ||
            strlen($config['proxySecret'] ?? '') < 32 || strlen($config['verifySecret'] ?? '') < 32) {
            throw new RuntimeException('Invalid Cap installation');
        }
        return $config;
    }

    public function post(string $action, array $body, string $ip = '')
    {
        if (!in_array($action, ['challenge', 'redeem', 'siteverify'], true)) throw new RuntimeException('Invalid action');
        $config = $this->configuration();
        if ($action === 'siteverify') $body['secret'] = $config['verifySecret'];
        if ($body === []) $body = ['action' => $action];
        // Fixed loopback destination prevents SSRF, independent of Host/X-Forwarded-* headers.
        return Http::connectTimeout(1)->timeout(6)->withOptions(['proxy' => ''])
            ->withHeaders(['X-Cap-Proxy-Secret' => $config['proxySecret'], 'X-Cap-Client-IP' => $ip])
            ->asJson()->post('http://127.0.0.1:' . $config['port'] . '/' . $config['siteKey'] . '/' . $action, $body);
    }

    public function verify($token, string $ip = ''): array
    {
        if (!is_string($token) || strlen($token) > 256 ||
            !preg_match('/^[a-zA-Z0-9_-]{8,64}:[a-f0-9]{32}:[a-f0-9]{64}$/D', $token)) {
            return [false, [400, __('Invalid code is incorrect')]];
        }
        try {
            // Do not retry: verification consumes the token atomically, exactly once.
            $response = $this->post('siteverify', ['response' => $token], $ip);
            if ($response->successful() && $response->json('success') === true) return [true, null];
            if ($response->serverError()) return [false, [503, '验证码服务暂不可用，请稍后重试']];
        } catch (\Throwable $e) {
            return [false, [503, '验证码服务暂不可用，请稍后重试']];
        }
        return [false, [400, __('Invalid code is incorrect')]];
    }
}
