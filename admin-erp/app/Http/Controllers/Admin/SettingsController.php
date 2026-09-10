<?php

namespace App\Http\Controllers\Admin;

use App\Models\Setting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A fixed, curated list of setting keys — like Permission, this screen does
 * not allow creating arbitrary keys from the form. Grouped to match how the
 * institutional site actually uses them (identity / contact / SEO).
 */
class SettingsController extends Controller
{
    /** @var array<string, array<int, string>> */
    private const GROUPS = [
        'identity' => ['site.name_bn', 'site.name_en', 'site.short_name', 'site.acronym', 'site.tagline'],
        'contact' => ['site.email', 'site.phone', 'site.address', 'site.facebook_url'],
        'seo' => ['site.seo_title', 'site.seo_description', 'site.alternate_names', 'site.website_url'],
        'links' => ['site.literature_url'],
    ];

    public function index(): View
    {
        $settings = Setting::query()->whereIn('key', $this->allKeys())->pluck('value', 'key');

        return view('admin.settings.index', [
            'title' => 'সাইট সেটিংস',
            'breadcrumbs' => [['label' => 'বিষয়বস্তু'], ['label' => 'সাইট সেটিংস']],
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site.name_bn' => ['required', 'string', 'max:255'],
            'site.name_en' => ['required', 'string', 'max:255'],
            'site.short_name' => ['required', 'string', 'max:100'],
            'site.acronym' => ['nullable', 'string', 'max:50'],
            'site.tagline' => ['nullable', 'string', 'max:255'],
            'site.email' => ['required', 'email', 'max:255'],
            'site.phone' => ['nullable', 'string', 'max:30'],
            'site.address' => ['nullable', 'string', 'max:1000'],
            'site.facebook_url' => ['nullable', 'url', 'max:255'],
            'site.seo_title' => ['nullable', 'string', 'max:255'],
            'site.seo_description' => ['nullable', 'string', 'max:500'],
            'site.website_url' => ['nullable', 'url', 'max:255'],
            'site.literature_url' => ['nullable', 'url', 'max:255'],
        ], [], [
            'site.name_bn' => 'বাংলা নাম', 'site.name_en' => 'ইংরেজি নাম', 'site.email' => 'ই-মেইল',
        ]);

        foreach ($data as $key => $value) {
            // Model save (not a query-builder update) so the saved-model hook
            // that clears Setting::get()'s cache actually fires.
            $setting = Setting::query()->where('key', $key)->first();
            $setting?->update(['value' => $value, 'updated_by' => $request->user()->id]);
        }

        return redirect()->route('admin.settings.index')->with('success', 'সেটিংস হালনাগাদ হয়েছে।');
    }

    /** @return array<int, string> */
    private function allKeys(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }
}
