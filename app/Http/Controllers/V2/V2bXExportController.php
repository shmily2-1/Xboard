<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\V2bX\Exporter;
use Illuminate\Http\Request;

class V2bXExportController extends Controller
{
    public function connections(Request $request)
    {
        $u = $request->user();
        abort_unless($u->isAvailable(), 403, '套餐不可用');
        $nodes = Server::whereJsonContains('group_ids', (string) $u->group_id)->where('show', true)
            ->where('backend_profile', 'v2bx-extended')->get(['id', 'name', 'type']);
        return $this->success(['nodes' => $nodes, 'services' => []]);
    }

    public function export(Request $request)
    {
        $p = $request->validate(['kind' => 'required|in:node', 'id' => 'required|integer']);
        $u = $request->user();
        abort_unless($u->isAvailable(), 403, '套餐不可用');
        $data = json_encode(Exporter::node(Server::findOrFail($p['id']), $u), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return response($data, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
