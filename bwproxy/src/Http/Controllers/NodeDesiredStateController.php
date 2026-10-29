<?php

namespace BWProject\BWProxy\Http\Controllers;

use BWProject\BWProxy\Models\ServerProxySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class NodeDesiredStateController extends Controller
{
    public function __invoke(string $nodeId): JsonResponse
    {
        $settings = ServerProxySetting::query()
            ->where('node_id', $nodeId)
            ->get()
            ->map(fn (ServerProxySetting $setting): array => [
                'server_uuid' => $setting->server_uuid,
                'mode' => $setting->mode,
                'socks5_host' => $setting->socks5_host,
                'socks5_port' => $setting->socks5_port,
                'socks5_username' => $setting->socks5_username,
                'socks5_password' => $setting->socks5_password,
                'awg_interface' => $setting->awg_interface,
                'updated_at' => $setting->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        return response()->json([
            'schema_version' => 1,
            'node_id' => (string) $nodeId,
            'servers' => $settings,
        ])->header('Cache-Control', 'no-store, private');
    }
}
