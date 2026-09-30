<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomepageCarouselSlide extends Model
{
    public const STATUSES = ['active' => 'সক্রিয়', 'inactive' => 'নিষ্ক্রিয়'];

    protected $fillable = [
        'image_path', 'title', 'title_en', 'alt_text', 'alt_text_en',
        'link_url', 'link_label', 'link_label_en', 'sort_order', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function hasLink(): bool
    {
        return filled($this->link_url) && filled($this->link_label);
    }
}
