<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\SingBox;
use App\Services\ServerService;
use App\Support\Hysteria2PortHopping;
use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Uses real Eloquent accessors and validators; no application boot or external services. */
final class Hysteria2PortHoppingCompatibilityTest extends TestCase
{
    private function node(array $changes = []): Server
    {
        return (new Server())->forceFill(array_replace_recursive([
            'type' => 'hysteria', 'name' => 'Local test only', 'host' => 'node.example.invalid',
            'port' => '20000-30000', 'server_port' => 8443,
            'protocol_settings' => ['version' => 2, 'hop_interval' => 30,
                'tls' => ['server_name' => 'node.example.invalid', 'allow_insecure' => false],
                'bandwidth' => ['up' => 100, 'down' => 100], 'obfs' => ['open' => false]],
        ], $changes));
    }

    public static function validPorts(): array
    {
        return [['1', null], [65535, null], ['1-65535','1-65535'], ['8443-8443','8443-8443'],
            [' 20000-30000 ', '20000-30000'], ['00001-00005','1-5']];
    }

    #[DataProvider('validPorts')]
    public function testPortBoundaryNormalization($value, $expected): void
    {
        self::assertSame($expected, Hysteria2PortHopping::ports($value));
    }

    public static function invalidPorts(): array
    {
        return array_map(fn ($v) => [$v], ['', null, false, 8443.5, [], '0', '65536', '-1',
            '20000,20002', '20000-21000,22000', '30000-20000', '0-100', '1-65536',
            '20000-', '*', '1e3', '1:5', '1--5', '1 - 5', '2-3-4']);
    }

    #[DataProvider('invalidPorts')]
    public function testInvalidPortsCannotReachTheNode($value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Hysteria2PortHopping::ports($value);
    }

    public static function validIntervals(): array
    {
        return [[null,null], ['',null], [0,0], ['0',0], [5,5], ['30',30], [3600,3600]];
    }

    #[DataProvider('validIntervals')]
    public function testIntervalDefaultsAndMinimum($value, $expected): void
    {
        self::assertSame($expected, Hysteria2PortHopping::interval($value));
    }

    public static function invalidIntervals(): array
    {
        return array_map(fn ($v) => [$v], [-1,1,4,'4','4.5',4.5,'abc','30s',true,[]]);
    }

    #[DataProvider('invalidIntervals')]
    public function testIntervalsAreValidatedBeforeModelCasting($value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Hysteria2PortHopping::interval($value);
    }

    public function testListenerCannotAccidentallyBecomeTheRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerService::buildNodeConfig($this->node(['server_port'=>'20000-30000']));
    }

    public function testConfigUsesOwnPortAndKeepsTlsObfsAndFixedListener(): void
    {
        $child = $this->node(['port'=>'22000-23000', 'protocol_settings'=>[
            'obfs'=>['open'=>true,'type'=>'salamander','password'=>'test-only-password']]]);
        $child->setRelation('parent', $this->node(['port'=>'40000-50000']));
        $config = ServerService::buildNodeConfig($child);
        self::assertSame('hysteria', $config['protocol']);
        self::assertSame(2, $config['version']);
        self::assertSame(8443, $config['server_port']);
        self::assertSame('22000-23000', $config['ports']);
        self::assertSame(30, $config['hop_interval']);
        self::assertSame('node.example.invalid', $config['server_name']);
        self::assertSame('salamander', $config['obfs']);
        self::assertSame('test-only-password', $config['obfs-password']);
    }

    private function validator(array $changes = [])
    {
        $payload = array_replace_recursive($this->node()->getAttributes(), $changes);
        $payload['protocol_settings'] = $changes['protocol_settings'] ?? $this->node()->protocol_settings;
        $payload['rate'] = 1;
        $request = ServerSave::create('/local-test', 'POST', $payload);
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'), new Container());
        $validator = $factory->make($payload, $request->rules(), $request->messages());
        $request->withValidator($validator);
        return $validator;
    }

    public function testValidAdminSettingsPassWithoutDatabaseAccess(): void
    {
        self::assertTrue($this->validator()->passes());
        self::assertTrue($this->validator(['port'=>8443])->passes());
        foreach ([null,0,5,30] as $interval) {
            self::assertTrue($this->validator(['protocol_settings'=>['version'=>2,'hop_interval'=>$interval]])->passes());
        }
    }

    public static function invalidAdminSettings(): array
    {
        return [
            [['port'=>'20000,22000'],'port'],
            [['port'=>'30000-20000'],'port'],
            [['port'=>'0-100'],'port'],
            [['port'=>['20000','30000']],'port'],
            [['server_port'=>'20000-30000'],'server_port'],
            [['server_port'=>65536],'server_port'],
            [['protocol_settings'=>['version'=>2,'hop_interval'=>4]],'protocol_settings.hop_interval'],
            [['protocol_settings'=>['version'=>2,'hop_interval'=>-1]],'protocol_settings.hop_interval'],
            [['protocol_settings'=>['version'=>2,'hop_interval'=>'abc']],'protocol_settings.hop_interval'],
            [['protocol_settings'=>['version'=>2,'hop_interval'=>4.5]],'protocol_settings.hop_interval'],
        ];
    }

    #[DataProvider('invalidAdminSettings')]
    public function testInvalidAdminSettingsFailOnTheCorrectField(array $changes, string $field): void
    {
        $validator = $this->validator($changes);
        self::assertFalse($validator->passes());
        self::assertTrue($validator->errors()->has($field));
    }

    public function testHysteriaOneValidationAndNodeOutputAreUnchanged(): void
    {
        $settings = ['version'=>1,'hop_interval'=>4];
        self::assertTrue($this->validator(['port'=>'20000-30000','protocol_settings'=>$settings])->passes());
        $config = ServerService::buildNodeConfig($this->node(['protocol_settings'=>$settings]));
        self::assertArrayNotHasKey('ports', $config);
        self::assertArrayNotHasKey('hop_interval', $config);
    }

    public function testOtherProtocolsDoNotReceiveHysteriaFields(): void
    {
        $node = (new Server())->forceFill(['type'=>'vmess','port'=>443,'server_port'=>8443,
            'host'=>'node.example.invalid','protocol_settings'=>['tls'=>1,'network'=>'tcp']]);
        $config = ServerService::buildNodeConfig($node);
        self::assertSame('vmess', $config['protocol']);
        self::assertArrayNotHasKey('ports', $config);
        self::assertArrayNotHasKey('hop_interval', $config);
    }

    private function subscriptionServer($interval = 30): array
    {
        // Match getAvailableServers(): random public port plus preserved public range.
        $node = $this->node(['protocol_settings'=>['hop_interval'=>$interval]]);
        return ['type'=>'hysteria', 'name'=>$node->name, 'host'=>$node->host, 'port'=>20001,
            'ports'=>'20000-30000','protocol_settings'=>$node->protocol_settings];
    }

    public function testClashMetaAndGeneralLinkPreservePublicRange(): void
    {
        $node = $this->subscriptionServer();
        $yamlNode = ClashMeta::buildHysteria('test-only-uuid', $node, []);
        self::assertSame('hysteria2', $yamlNode['type']);
        self::assertSame(20001, $yamlNode['port']);
        self::assertSame('20000-30000', $yamlNode['ports']);
        self::assertSame(30, $yamlNode['hop-interval']);
        $uri = trim(General::buildHysteria('test-only-uuid', $node));
        parse_str(parse_url($uri, PHP_URL_QUERY), $query);
        self::assertSame('20000-30000', $query['mport']);
        self::assertSame(20001, parse_url($uri, PHP_URL_PORT));
        self::assertSame('node.example.invalid', $query['sni']);
        self::assertSame('0', $query['insecure']);
    }

    private function singBox(string $version): SingBox
    {
        // Skip the unrelated template/hook constructor, but use the real version gate/exporter.
        $reflection = new \ReflectionClass(SingBox::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        foreach (['clientName'=>'sing-box', 'clientVersion'=>$version] as $key=>$value) {
            $reflection->getProperty($key)->setValue($instance, $value);
        }
        return $instance;
    }

    private function singBoxNode($interval, string $version = '1.11.0'): array
    {
        $method = new \ReflectionMethod(SingBox::class, 'buildHysteria');
        return $method->invoke($this->singBox($version), 'test-only-uuid', $this->subscriptionServer($interval));
    }

    public function testSingBoxHoppingVersionGate(): void
    {
        $new = $this->singBoxNode(30);
        self::assertSame(['20000:30000'], $new['server_ports']);
        self::assertSame('30s', $new['hop_interval']);
        self::assertSame(20001, $new['server_port']);
        self::assertSame('hysteria2', $new['type']);
        $old = $this->singBoxNode(30, '1.10.0');
        self::assertArrayNotHasKey('server_ports', $old);
        self::assertArrayNotHasKey('hop_interval', $old);
    }

    public function testZeroAndNullIntervalsDoNotEmitZeroSecondTimers(): void
    {
        foreach ([0,null] as $interval) {
            $config = ServerService::buildNodeConfig($this->node(['protocol_settings'=>['hop_interval'=>$interval]]));
            self::assertSame($interval, $config['hop_interval']);
            self::assertArrayNotHasKey('hop_interval', $this->singBoxNode($interval));
            $clash = ClashMeta::buildHysteria('test-only-uuid', $this->subscriptionServer($interval), []);
            self::assertArrayNotHasKey('hop-interval', $clash);
        }
    }
}
