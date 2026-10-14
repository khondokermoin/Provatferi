<?php

namespace App\Models;

use App\Observers\SettingObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Every save or delete of a public row tells the public site to drop its cached settings (SettingObserver).
 */
#[ObservedBy([SettingObserver::class])]
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
        // Block closures that return nothing, on purpose. An arrow function would return Cache::forget()'s result, and
        // that is FALSE whenever the key was not cached (the array and file stores): Eloquent stops running the
        // remaining listeners of an event as soon as one returns false, so the observer registered after this hook
        // (SettingObserver, the public-site revalidation) silently never ran. Found 2026-10-14.
        static::saved(function (self $setting): void {
            Cache::forget("setting:{$setting->key}");
        });
        static::deleted(function (self $setting): void {
            Cache::forget("setting:{$setting->key}");
        });
    }
}
