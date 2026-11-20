<?php

// Source: shmily2-1/V2bX-2, commit 7906e9e, docs/patches/Xboard-hysteria2-port-hopping.patch
// SPDX-License-Identifier: MPL-2.0; see windows/third-party/V2bX-2.LICENSE.

namespace Tests\Unit;

use App\Models\Server;
use App\Services\ServerService;
use PHPUnit\Framework\TestCase;

// No Laravel application boot, database connection, Redis, or network requests.
final class Hysteria2PortHoppingTest extends TestCase
{
    private function node(string $port, array $settings = []): Server
    {
        return (new Server())->forceFill([
            'type' => 'hysteria',
            'host' => 'localhost',
            'port' => $port,
            'server_port' => 8443,
            'protocol_settings' => array_replace_recursive([
                'version' => 2,
                'tls' => ['server_name' => 'localhost'],
                'bandwidth' => ['up' => 100, 'down' => 100],
                'obfs' => ['open' => false],
            ], $settings),
        ]);
    }

    public function testRangeDoesNotReplaceBackendListener(): void
    {
        $config = ServerService::buildNodeConfig($this->node('20000-30000', ['hop_interval' => 30]));
        self::assertSame(8443, $config['server_port']);
        self::assertSame('20000-30000', $config['ports']);
        self::assertSame(30, $config['hop_interval']);
        self::assertSame(2, $config['version']);
        self::assertNull($config['obfs']);
    }

    public function testSinglePortAndDefaultIntervalStayOptional(): void
    {
        $config = ServerService::buildNodeConfig($this->node('8443'));
        self::assertNull($config['ports']);
        self::assertNull($config['hop_interval']);
    }

    public function testHysteriaOneUnchanged(): void
    {
        $config = ServerService::buildNodeConfig($this->node('20000-30000', ['version' => 1]));
        self::assertArrayNotHasKey('ports', $config);
        self::assertArrayNotHasKey('hop_interval', $config);
    }

    public function testObfuscationAndChildPortPreserved(): void
    {
        $child = $this->node('22000-23000', ['obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'test-only']]);
        $child->setRelation('parent', $this->node('20000-21000'));
        $config = ServerService::buildNodeConfig($child);
        self::assertSame('22000-23000', $config['ports']);
        self::assertSame('salamander', $config['obfs']);
        self::assertSame('test-only', $config['obfs-password']);
    }

    public function testUnsupportedOrMalformedPortsFailExplicitly(): void
    {
        foreach (['20000,20002', '30000-20000', '0-100', '1-65536', '20000-', '*'] as $port) {
            try {
                ServerService::buildNodeConfig($this->node($port));
                self::fail("Expected invalid port: {$port}");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Hysteria2', $exception->getMessage());
            }
        }
    }

    public function testTooShortIntervalRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerService::buildNodeConfig($this->node('20000-30000', ['hop_interval' => 4]));
    }
}
