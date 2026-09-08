<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'value_type', 'group_name', 'is_public', 'updated_by'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("setting:{$key}", function () use ($key, $default) {
            $row = self::query()->where('key', $key)->first();

            if (! $row) {
                return $default;
            }

            return match ($row->value_type) {
                'boolean' => filter_var($row->value, FILTER_VALIDATE_BOOLEAN),
                'number' => is_numeric($row->value) ? $row->value + 0 : $row->value,
                'json' => json_decode((string) $row->value, true),
                default => $row->value,
            };
        });
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => Cache::forget("setting:{$setting->key}"));
        static::deleted(fn (self $setting) => Cache::forget("setting:{$setting->key}"));
    }
}
