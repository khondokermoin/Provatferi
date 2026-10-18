<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Support\Recruitment\CentralMessengerGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin -> Recruitment -> Volunteer Messenger group (docs/VOLUNTEER_MESSENGER_GROUP.md): the switch and the invite link of
 * the ONE central group an applicant is offered after a verified application. Two private settings, nothing else; the
 * form cannot write any other key.
 *
 * Authorization: `settings.update` for both the page and the save (routes/web.php; EnsurePermission) — it is a site-wide
 * setting, and the link itself is shown only to someone who may change it. What may be saved is a Messenger group invite
 * and nothing else (CentralMessengerGroup::normalize), stored in its canonical form whichever form was pasted.
 *
 * The link is not logged: the log records who changed what, never the value.
 */
class RecruitmentMessengerController extends Controller
{
    public function edit(): View
    {
        $rows = Setting::query()->whereIn('key', [CentralMessengerGroup::KEY_ENABLED, CentralMessengerGroup::KEY_URL])->get()->keyBy('key');
        $changedRow = $rows->sortByDesc('updated_at')->first();

        return view('admin.recruitment.messenger', [
            'title' => __('admin.messenger.page_title'),
            'breadcrumbs' => [['label' => __('admin.nav.recruitment')], ['label' => __('admin.messenger.page_title')]],
            'enabled' => CentralMessengerGroup::enabled(),
            'url' => CentralMessengerGroup::inviteUrl(),
            'changedBy' => $changedRow?->updated_by ? User::query()->whereKey($changedRow->updated_by)->value('name') : null,
            'changedAt' => $changedRow?->updated_at,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'central_messenger_enabled' => ['nullable', 'boolean'],
            'central_messenger_invite_url' => ['nullable', 'string', 'max:500'],
        ], [], ['central_messenger_invite_url' => __('admin.messenger.url_label')]);

        $enabled = $request->boolean('central_messenger_enabled');
        $raw = trim((string) $request->input('central_messenger_invite_url', ''));
        $url = $raw === '' ? null : CentralMessengerGroup::normalize($raw);

        if ($raw !== '' && $url === null) {
            throw ValidationException::withMessages(['central_messenger_invite_url' => __('admin.messenger.invalid_url')]);
        }
        if ($enabled && $url === null) {
            throw ValidationException::withMessages(['central_messenger_invite_url' => __('admin.messenger.required_when_enabled')]);
        }

        $userId = $request->user()->id;
        $urlChanged = $url !== CentralMessengerGroup::inviteUrl();
        $enabledChanged = $enabled !== CentralMessengerGroup::enabled();

        DB::transaction(function () use ($enabled, $url, $userId): void {
            // Model saves (updateOrCreate), so the cache entry behind Setting::get() is dropped with each write.
            Setting::query()->updateOrCreate(
                ['key' => CentralMessengerGroup::KEY_ENABLED],
                ['value' => $enabled ? 'true' : 'false', 'value_type' => 'boolean', 'group_name' => CentralMessengerGroup::GROUP, 'is_public' => false, 'updated_by' => $userId],
            );
            Setting::query()->updateOrCreate(
                ['key' => CentralMessengerGroup::KEY_URL],
                ['value' => $url, 'value_type' => 'string', 'group_name' => CentralMessengerGroup::GROUP, 'is_public' => false, 'updated_by' => $userId],
            );
        });

        if ($urlChanged || $enabledChanged) {
            Log::info('Volunteer Messenger group setting changed.', ['user_id' => $userId, 'enabled' => $enabled, 'invite_changed' => $urlChanged]);
        }

        return redirect()->route('admin.recruitment.messenger.edit')
            ->with('success', __($enabled ? 'admin.messenger.saved_on' : 'admin.messenger.saved_off'));
    }
}
