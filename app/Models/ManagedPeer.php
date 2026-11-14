<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedPeer extends Model
{
    protected $table = 'v2_managed_peer';
    protected $guarded = ['id'];
    protected $hidden = ['preshared_key'];
    protected $casts = ['preshared_key' => 'encrypted', 'addresses' => 'array',
        'enabled' => 'boolean', 'deleting' => 'boolean', 'retired' => 'boolean'];
}
