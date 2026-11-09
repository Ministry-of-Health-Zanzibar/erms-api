<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRoleChange extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'previous_roles' => 'array',
        'new_roles' => 'array',
        'changed_by_roles' => 'array',
        'created_at' => 'datetime',
    ];
}
