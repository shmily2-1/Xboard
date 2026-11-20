<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\CapService;
use Illuminate\Http\Request;

class CapController extends Controller
{
    public function handle(Request $request, string $action, CapService $cap)
    {
        // Installation does not enable CAPTCHA. Keep challenge endpoints dormant until explicitly selected.
        if (!(int) admin_setting('captcha_enable', 0) || admin_setting('captcha_type', 'recaptcha') !== 'cap') abort(404);
        if (!in_array($action, ['challenge', 'redeem'], true)) abort(404);
        if (strlen($request->getContent()) > 65536) abort(413);
        $body = [];
        if ($action === 'redeem') {
            $body = $request->validate([
                'token' => 'required|string|max:16384', 'solutions' => 'required|array|max:200',
                'instr' => 'sometimes|array', 'instr_blocked' => 'sometimes|boolean', 'instr_timeout' => 'sometimes|boolean',
            ]);
        }
        try {
            $response = $cap->post($action, $body, $request->ip());
            $data = $response->json();
            if (!is_array($data)) throw new \RuntimeException('Invalid response');
            return response()->json($data, $response->status())->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'captcha_unavailable'], 503)->header('Cache-Control', 'no-store');
        }
    }
}
