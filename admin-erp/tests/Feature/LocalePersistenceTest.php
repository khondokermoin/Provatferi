<?php

namespace Tests\Feature;

use App\Support\AdminLocale;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 Step 6: the admin locale switcher (LocaleController + the
 * SetAdminLocale middleware documented precedence: signed-in user's own
 * ui_locale column > session > cookie > default 'bn') is what makes an
 * admin's language choice survive a reload, a new tab, or coming back next
 * week rather than resetting on every request. None of this had direct
 * test coverage before Phase 3 Step 6 — BilingualEditorTest and
 * BilingualApplicationDocumentTest only ever set ui_locale directly on a
 * user, never exercised the switcher endpoint or the fallback chain itself.
 */
class LocalePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): \App\Models\User
    {
        $user = \App\Models\User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
        $user->roles()->attach(\App\Models\Role::query()->where('slug', 'super_admin')->firstOrFail());

        return $user;
    }

    public function test_switching_locale_persists_the_choice_to_the_users_own_account(): void
    {
        $admin = $this->admin();
        $this->assertNull($admin->ui_locale);

        $this->actingAs($admin)->post(route('locale.update'), ['locale' => 'en'])->assertRedirect();

        $this->assertSame('en', $admin->fresh()->ui_locale);
    }

    public function test_the_switched_locale_is_used_on_the_very_next_request_without_relogging_in(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('locale.update'), ['locale' => 'en'])->assertRedirect();

        // No new login, no explicit locale param on this request — the
        // account-level preference set a moment ago is what's read here.
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Provatferi Literary and Cultural Center')
            ->assertDontSee('প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র');
    }

    public function test_switching_back_to_bangla_also_persists(): void
    {
        $admin = $this->admin();
        $admin->update(['ui_locale' => 'en']);

        $this->actingAs($admin)->post(route('locale.update'), ['locale' => 'bn'])->assertRedirect();

        $this->assertSame('bn', $admin->fresh()->ui_locale);
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()->assertSee('প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র');
    }

    public function test_an_unsupported_locale_value_is_rejected_and_changes_nothing(): void
    {
        $admin = $this->admin();
        $admin->update(['ui_locale' => 'bn']);

        $this->actingAs($admin)->post(route('locale.update'), ['locale' => 'fr'])
            ->assertSessionHasErrors('locale');

        $this->assertSame('bn', $admin->fresh()->ui_locale);
    }

    public function test_a_signed_in_users_own_locale_wins_over_a_contradicting_session_and_cookie(): void
    {
        $admin = $this->admin();
        $admin->update(['ui_locale' => 'en']);

        $this->actingAs($admin)
            ->withSession([AdminLocale::SESSION_KEY => 'bn'])
            ->withCookie(AdminLocale::COOKIE_NAME, 'bn')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Provatferi Literary and Cultural Center');
    }

    public function test_the_session_is_honoured_before_login_when_there_is_no_user_yet(): void
    {
        $this->withSession([AdminLocale::SESSION_KEY => 'en'])
            ->get(route('login'))
            ->assertOk()
            ->assertSee('lang="en"', false);
    }

    public function test_the_cookie_is_honoured_before_login_when_there_is_no_session_value(): void
    {
        $this->withCookie(AdminLocale::COOKIE_NAME, 'en')
            ->get(route('login'))
            ->assertOk()
            ->assertSee('lang="en"', false);
    }

    public function test_session_wins_over_a_contradicting_cookie_when_there_is_no_user(): void
    {
        $this->withSession([AdminLocale::SESSION_KEY => 'en'])
            ->withCookie(AdminLocale::COOKIE_NAME, 'bn')
            ->get(route('login'))
            ->assertOk()
            ->assertSee('lang="en"', false);
    }

    public function test_a_guest_with_no_session_or_cookie_value_gets_the_bangla_default(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('lang="bn"', false);
    }

    public function test_an_unsupported_cookie_value_falls_back_to_the_default_instead_of_erroring(): void
    {
        $this->withCookie(AdminLocale::COOKIE_NAME, 'not-a-real-locale')
            ->get(route('login'))
            ->assertOk()
            ->assertSee('lang="bn"', false);
    }
}
