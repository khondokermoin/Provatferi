<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The site settings screen: the organisation's identity, institutional contact, social links, location and search-engine
 * info. A fixed list of settings (App\Support\SiteSettings), like Permission: the form cannot create or write any other
 * key. The values it saves are what the public site shows (docs/PUBLIC_CONTACT_SETTINGS.md).
 *
 * Authorization: `settings.view` to open it, `settings.update` to save (routes/web.php; EnsurePermission). The update
 * route also carries `revalidate.public`, so the public site has dropped its cached settings before the admin sees
 * "saved".
 */
class SettingsController extends Controller
{
    public function index(): View
    {
        $settings = Setting::query()->whereIn('key', SiteSettings::keys())->pluck('value', 'key');

        return view('admin.settings.index', [
            'title' => __('admin.nav.site_settings'),
            'breadcrumbs' => [['label' => __('admin.nav.groups.content')], ['label' => __('admin.nav.site_settings')]],
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // Invalid input redirects back with every error and the admin's own input; nothing is half-saved.
        $validated = SiteSettings::validator($request->all())->validate();
        $values = SiteSettings::normalize($validated);
        $userId = $request->user()->id;

        $changed = DB::transaction(function () use ($values, $userId): array {
            $existing = Setting::query()->whereIn('key', array_keys($values))->get()->keyBy('key');
            $changed = [];

            foreach ($values as $key => $value) {
                $row = $existing->get($key);
                // Only a real change writes a row, so a save that changes nothing touches nothing and purges nothing.
                if ($row !== null && (string) ($row->value ?? '') === (string) ($value ?? '')) {
                    continue;
                }

                // A key added by this release is created on first save: public, like every `site.*` setting.
                $row ??= new Setting(['key' => $key, 'value_type' => SiteSettings::valueTypeFor($key), 'group_name' => 'site', 'is_public' => true]);
                // Model save (not a query-builder update) so the hooks fire: the cache entry of Setting::get()
                // and the public-site revalidation (SettingObserver).
                $row->value = $value;
                $row->updated_by = $userId;
                $row->save();
                $changed[] = $key;
            }

            return $changed;
        });

        if ($changed === []) {
            return redirect()->route('admin.settings.index')->with('status', __('admin.contact_settings.nothing_changed'));
        }

        // Keys only: who changed which settings. The values are public data and are in the table.
        Log::info('Site settings changed.', ['user_id' => $userId, 'keys' => $changed]);

        return redirect()->route('admin.settings.index')->with('success', __('admin.flash.settings_updated'));
    }
}
