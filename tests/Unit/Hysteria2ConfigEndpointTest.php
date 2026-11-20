<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Server\UniProxyController;
use App\Models\Server;
use App\Services\DeviceStateService;
use App\Support\Setting;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\Router;
use PHPUnit\Framework\TestCase;

/** Test route mappings and the real controller/ETag with memory models, not a live node. */
final class Hysteria2ConfigEndpointTest extends TestCase
{
    private $previousContainer;
    private Container $container;
    private UniProxyController $controller;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->container->instance('config', new Repository(['v2board'=>[]]));
        $settings = $this->createStub(Setting::class);
        $settings->method('get')->willReturn(null);
        $this->container->instance(Setting::class, $settings);
        $this->container->instance(ResponseFactoryContract::class, new ResponseFactory(
            $this->createStub(ViewFactory::class), $this->createStub(Redirector::class)));
        $this->controller = new UniProxyController($this->createStub(DeviceStateService::class));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
    }

    private function request(string $port = '20000-30000', ?string $etag = null): Request
    {
        $node = (new Server())->forceFill(['type'=>'hysteria', 'port'=>$port, 'server_port'=>8443,
            'host'=>'node.example.invalid', 'protocol_settings'=>['version'=>2,'hop_interval'=>30,
                'tls'=>['server_name'=>'node.example.invalid'], 'bandwidth'=>['up'=>100,'down'=>100], 'obfs'=>['open'=>false]]]);
        $request = Request::create('/api/v1/server/UniProxy/config', 'GET');
        $request->attributes->set('node_info', $node);
        if ($etag !== null) $request->headers->set('If-None-Match', $etag);
        return $request;
    }

    public function testBothAuthenticatedRouteGroupsStillUseTheSharedController(): void
    {
        $router = new Router(new Dispatcher($this->container), $this->container);
        $router->group(['prefix'=>'api/v1'], fn ($r) => (new \App\Http\Routes\V1\ServerRoute())->map($r));
        $router->group(['prefix'=>'api/v2'], fn ($r) => (new \App\Http\Routes\V2\ServerRoute())->map($r));
        foreach (['/api/v1/server/UniProxy/config'=>'server', '/api/v2/server/config'=>'server.v2'] as $path=>$middleware) {
            $route = $router->getRoutes()->match(Request::create($path, 'GET'));
            self::assertSame(UniProxyController::class . '@config', $route->getActionName());
            self::assertContains($middleware, $route->middleware());
        }
    }

    public function testRealConfigResponseRetainsBaseConfigurationAndNewFields(): void
    {
        $response = $this->controller->config($this->request());
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(8443, $body['server_port']);
        self::assertSame('20000-30000', $body['ports']);
        self::assertSame(30, $body['hop_interval']);
        self::assertSame(['push_interval'=>60,'pull_interval'=>60], $body['base_config']);
        self::assertNotEmpty($response->headers->get('ETag'));
    }

    public function testChangingTheRangeInvalidatesTheEtag(): void
    {
        $first = $this->controller->config($this->request());
        $etag = $first->headers->get('ETag');
        $same = $this->controller->config($this->request(etag: $etag));
        self::assertSame(304, $same->getStatusCode());
        $changed = $this->controller->config($this->request('22000-23000', $etag));
        self::assertSame(200, $changed->getStatusCode());
        self::assertNotSame($etag, $changed->headers->get('ETag'));
    }
}
