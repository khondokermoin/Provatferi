<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminNotification extends Model
{
    protected $fillable = ['type', 'title', 'body', 'link', 'required_permission', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function reads(): HasMany
    {
        return $this->hasMany(AdminNotificationRead::class);
    }

    /** Notifications a given user is permitted to see: no gate, or a permission they hold. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->whereNull('required_permission')
                ->orWhereIn('required_permission', $user->permissionSlugs());
        });
    }

    public function isReadBy(User $user): bool
    {
        return $this->relationLoaded('reads')
            ? $this->reads->contains('user_id', $user->id)
            : $this->reads()->where('user_id', $user->id)->exists();
    }

    /**
     * Rendered in the CURRENT VIEWER's locale from lang/{bn,en}/admin.php's
     * `notifications.{type}.title` — never a string baked in at creation
     * time, so the same event reads correctly for every admin regardless of
     * their own language choice. Falls back to the literal `title` column
     * (used only for a genuinely one-off/ad-hoc notification with no type
     * template) when no such key exists.
     */
    public function resolvedTitle(): string
    {
        $key = "admin.notifications.{$this->type}.title";

        return trans()->has($key) ? __($key, $this->meta ?? []) : (string) $this->title;
    }

    public function resolvedBody(): ?string
    {
        $key = "admin.notifications.{$this->type}.body";
        if (trans()->has($key)) {
            return __($key, $this->meta ?? []);
        }

        return $this->body;
    }
}
