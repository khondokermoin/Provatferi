<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton: always exactly one row. Use AboutPage::current() rather than
 * querying directly, so callers never have to think about the id.
 */
class AboutPage extends Model
{
    protected $table = 'about_page';

    protected $fillable = [
        'introduction', 'description', 'history', 'why_exists',
        'identity_explanation', 'registration_status', 'is_published', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }
}
