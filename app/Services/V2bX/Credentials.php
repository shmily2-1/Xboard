<?php

namespace App\Services\V2bX;

use App\Models\ProtocolCredential;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Support\Facades\DB;

class Credentials
{
    public static function required(Server $node): bool
    {
        return $node->backend_profile === 'v2bx-extended'
            && in_array($node->type, ['http', 'mixed', 'masque'], true);
    }

    public static function secret(int $uid): string
    {
        $credential = ProtocolCredential::firstOrCreate(
            ['user_id' => $uid], ['secret' => bin2hex(random_bytes(24))]
        );
        return $credential->secret;
    }

    public static function rotate(User $user): void
    {
        DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $credential = ProtocolCredential::firstOrNew(['user_id' => $user->id]);
            $credential->secret = bin2hex(random_bytes(24));
            $credential->version = ($credential->version ?? 0) + 1;
            $credential->save();
        });
        NodeSyncService::notifyUserChanged($user);
    }

    public static function serialize($user, Server $node): array
    {
        $out = ['id' => (int) $user->id, 'uuid' => $user->uuid,
            'speed_limit' => (int) $user->speed_limit, 'device_limit' => (int) $user->device_limit];
        if (self::required($node)) {
            $out['password'] = self::secret((int) $user->id);
        }
        return $out;
    }
}
