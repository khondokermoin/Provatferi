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
        'introduction', 'introduction_en', 'description', 'description_en', 'history', 'history_en',
        'why_exists', 'why_exists_en', 'identity_explanation', 'identity_explanation_en',
        'registration_status', 'is_published', 'updated_by',
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
