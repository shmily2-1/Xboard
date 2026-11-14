<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedService extends Model
{
    protected $table = 'v2_managed_service';
    protected $guarded = ['id'];
    protected $hidden = ['spec'];
    protected $casts = ['spec' => 'encrypted:array', 'runtime' => 'array',
        'enabled' => 'boolean', 'deleting' => 'boolean'];
}
