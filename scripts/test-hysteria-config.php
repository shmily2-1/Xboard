<?php

// Offline regression: exercise the real model, API builder and subscription
// exporters without booting the application or connecting to a database.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\Server;
use App\Protocols\General;
use App\Protocols\Shadowrocket;
use App\Services\ServerService;
use App\Services\V2bX\NodeConfig;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;

$container = new Container();
Container::setInstance($container);
Facade::setFacadeApplication($container);
$container->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $container));
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$makeNode = static function (string $profile, string $port = '20000-30000', array $settings = []): Server {
    return (new Server())->forceFill([
        'type' => 'hysteria', 'backend_profile' => $profile, 'name' => 'Local HY2',
        'host' => '127.0.0.1', 'port' => $port, 'server_port' => 18443, 'rate' => 1,
        'route_ids' => [], 'protocol_settings' => array_replace_recursive([
            'version' => 2, 'tls' => ['server_name' => 'certificate.example.test'],
            'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'test-only-obfs'],
            'bandwidth' => ['up' => 100, 'down' => 100], 'hop_interval' => 30,
            'masquerade' => ['type' => 'proxy', 'url' => 'https://www.example.test'],
        ], $settings),
    ]);
};

foreach (['legacy', 'v2bx-extended'] as $profile) {
    $node = $makeNode($profile);
    $config = ServerService::buildNodeConfig($node);
    $expect($config['ports'] === '20000-30000', "$profile lost public port range");
    $expect($config['server_port'] === 18443, "$profile overwrote backend listener");
    $expect($config['hop_interval'] === 30, "$profile lost hop interval");
    $expect($config['server_name'] === 'certificate.example.test', "$profile lost certificate SNI");
    $expect($config['tls_settings']['server_name'] === $config['server_name'], "$profile SNI mismatch");
    $expect($config['masquerade']['url'] === 'https://www.example.test', "$profile lost masquerade");

    $child = $makeNode($profile, '31000-32000');
    $child->setRelation('parent', $node);
    $expect(ServerService::buildNodeConfig($child)['ports'] === '31000-32000', "$profile used parent port range");
    $single = ServerService::buildNodeConfig($makeNode($profile, '443', ['hop_interval' => null]));
    $expect($single['ports'] === null && $single['hop_interval'] === null, "$profile broke single-port config");

    foreach (['0-100', '30000-20000', '20000-65536', '20000,20001', 'invalid'] as $ports) {
        try {
            ServerService::buildNodeConfig($makeNode($profile, $ports));
            throw new RuntimeException("$profile accepted invalid range");
        } catch (ValidationException $e) {
            $expect(isset($e->errors()['protocol_settings']), 'missing validation error');
        }
    }
}

$legacyV1 = ServerService::buildNodeConfig($makeNode('legacy', '443', ['version' => 1]));
$expect(!array_key_exists('ports', $legacyV1), 'Hysteria1 unexpectedly enabled port hopping');
foreach ([0, 5, 30] as $seconds) {
    $expect(NodeConfig::portHopping($makeNode('v2bx-extended', '443-8000', ['hop_interval' => $seconds]))['hop_interval'] === $seconds, 'valid interval rejected');
}
foreach ([-1, 4] as $seconds) {
    try {
        NodeConfig::portHopping($makeNode('v2bx-extended', '443-8000', ['hop_interval' => $seconds]));
        throw new RuntimeException('invalid interval accepted');
    } catch (ValidationException $e) {
        $expect(true, 'invalid interval rejected');
    }
}

$server = $makeNode('v2bx-extended')->toArray();
$server['ports'] = $server['port'];
$server['port'] = 25000;
foreach ([General::class, Shadowrocket::class] as $exporter) {
    $uri = $exporter::buildHysteria('test-only-auth', $server);
    parse_str(parse_url(trim($uri), PHP_URL_QUERY), $query);
    $expect($query['sni'] === 'certificate.example.test', "$exporter lost SNI");
    $expect($query['mport'] === '20000-30000', "$exporter lost public port range");
    $expect($query['obfs-password'] === 'test-only-obfs', "$exporter lost obfuscation password");
    $expect(parse_url(trim($uri), PHP_URL_PORT) === 25000, "$exporter replaced subscription port");
}
echo "Hysteria API/subscription regression passed ($checks checks).\n";
