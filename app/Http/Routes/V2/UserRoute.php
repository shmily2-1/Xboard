<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V1\User\UserController;
use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            // User
            $router->get('/resetSecurity', [UserController::class, 'resetSecurity']);
            $router->get('/info', [UserController::class, 'info']);
            $router->get('/server/connections', [\App\Http\Controllers\V2\V2bXExportController::class, 'connections']);
            $router->post('/server/export', [\App\Http\Controllers\V2\V2bXExportController::class, 'export']);
            $router->post('/server/rotateCredentials', [\App\Http\Controllers\V2\V2bXController::class, 'rotate']);
        });
    }
}
