<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProtocolCredential extends Model
{
    protected $table = 'v2_protocol_credential';
    protected $guarded = ['id'];
    protected $hidden = ['secret'];
    protected $casts = ['secret' => 'encrypted'];
}
