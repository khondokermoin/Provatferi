<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentBlock extends Model
{
    protected $fillable = ['key', 'title', 'body', 'group_name', 'is_public', 'updated_by'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }
}
