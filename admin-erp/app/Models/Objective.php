<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Objective extends Model
{
    protected $fillable = ['title', 'body', 'sort_order', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }
}
