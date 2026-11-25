<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreModule extends Model
{
    protected $fillable = ['store_id', 'module', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
