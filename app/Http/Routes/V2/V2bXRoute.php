<?php

namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\V2bXController;
use App\Http\Controllers\V2\V2bXExportController;
use Illuminate\Contracts\Routing\Registrar;

class V2bXRoute
{
    public function map(Registrar $router)
    {
        $secure = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $router->group(['prefix' => $secure . '/v2bx', 'middleware' => ['admin', 'log']], function ($r) {
            $r->get('metadata', [V2bXController::class, 'metadata']);
            $r->get('nodes', [V2bXController::class, 'nodes']);
            $r->get('node', [V2bXController::class, 'nodeEdit']);
            $r->get('services', [V2bXController::class, 'services']);
            $r->post('services/save', [V2bXController::class, 'putService']);
            $r->post('services/action', [V2bXController::class, 'serviceAction']);
            $r->post('services/link', [V2bXController::class, 'serviceLink']);
            $r->post('peers/save', [V2bXController::class, 'putPeer']);
            $r->post('peers/remove', [V2bXController::class, 'removePeer']);
            $r->post('credentials/rotate', [V2bXController::class, 'rotate']);
        });
        $router->group(['prefix' => 'user/v2bx', 'middleware' => 'user'], function ($r) {
            $r->get('connections', [V2bXExportController::class, 'connections']);
            $r->post('export', [V2bXExportController::class, 'export']);
            $r->post('credentials/rotate', [V2bXController::class, 'rotate']);
        });
        $router->group(['prefix' => 'server/machine/v2bx'], function ($r) {
            $r->post('pull', [V2bXController::class, 'pull']);
            $r->post('ack', [V2bXController::class, 'ack']);
            $r->post('meter', [V2bXController::class, 'serviceMeter']);
        });
        $router->group(['prefix' => 'server/machine/services'], function ($r) {
            $r->post('pull', [V2bXController::class, 'pull']);
            $r->post('ack', [V2bXController::class, 'ack']);
            $r->post('meter', [V2bXController::class, 'serviceMeter']);
        });
    }
}
