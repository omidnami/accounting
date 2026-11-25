<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $fillable = [
        'code',
        'subdomain',
        'database_name',
        'status',
        'timezone',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
