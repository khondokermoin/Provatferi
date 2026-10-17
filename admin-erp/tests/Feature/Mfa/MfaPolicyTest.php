<?php

namespace Tests\Feature\Mfa;

use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\Setting;
use App\Support\Mfa\MfaPolicy;

/**
 * Who must use it, and how it is turned off (docs/MFA_SECURITY.md, "Role-based policy" and "Turning it off"): M and N, the
 * per-role switch, and the privileged-account rule.
 */
class MfaPolicyTest extends MfaTestCase
{
    // M — turning it off needs the password AND a current code.
    public function test_turning_it_off_needs_the_password_and_a_current_code(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);

        $this->post(route('admin.account.security.disable'), [])->assertSessionHasErrors(['password', 'code']);
        $this->post(route('admin.account.security.disable'), ['password' => 'not-the-password', 'code' => $this->codeFor($secret)])->assertSessionHasErrors('password');
        $this->post(route('admin.account.security.disable'), ['password' => self::PASSWORD, 'code' => '12345'])->assertSessionHasErrors('code');
        $this->assertTrue($user->fresh()->hasMfaEnabled());

        $this->post(route('admin.account.security.disable'), ['password' => self::PASSWORD, 'code' => $this->codeFor($secret)])
            ->assertRedirect(route('admin.account.security'))->assertSessionHas('success');

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasMfaEnabled());
        $this->assertNull($fresh->getRawOriginal('mfa_secret'));
        $this->assertNull($fresh->getRawOriginal('mfa_recovery_codes'));
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => SecurityEvent::MFA_DISABLED]);
    }

    public function test_a_recovery_code_can_stand_in_for_the_app_when_turning_it_off(): void
    {
        $user = $this->staff();
        ['secret' => $secret, 'codes' => $codes] = $this->enrol($user);
        $this->signInWithCode($user, $secret);

        $this->post(route('admin.account.security.disable'), ['password' => self::PASSWORD, 'code' => $codes[4]])->assertSessionHas('success');
        $this->assertFalse($user->fresh()->hasMfaEnabled());
    }

    // N — an account that must use it cannot turn it off.
    public function test_an_account_whose_role_requires_it_cannot_turn_it_off(): void
    {
        $user = $this->staffWithRole($this->roleWith(['activities.view'], mfaRequired: true));
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);

        $this->get(route('admin.account.security'))->assertOk()
            ->assertSee('data-mfa-disable="locked"', false)->assertDontSee('id="mfa-disable-form"', false);
        $this->post(route('admin.account.security.disable'), ['password' => self::PASSWORD, 'code' => $this->codeFor($secret)])
            ->assertSessionHas('error', __('admin.mfa.disable_not_allowed'));

        $this->assertTrue($user->fresh()->hasMfaEnabled());
        $this->assertDatabaseMissing('security_events', ['user_id' => $user->id, 'event' => SecurityEvent::MFA_DISABLED]);
    }

    public function test_a_super_admin_cannot_turn_it_off_once_the_privileged_rule_is_on(): void
    {
        $owner = $this->superAdmin();
        ['secret' => $secret] = $this->enrol($owner);
        $this->signInWithCode($owner, $secret);
        $this->post(route('admin.account.security.enforcement'), ['enabled' => '1'])->assertSessionHas('success');

        $this->nextStep();
        $this->post(route('admin.account.security.disable'), ['password' => self::PASSWORD, 'code' => $this->codeFor($secret)])
            ->assertSessionHas('error', __('admin.mfa.disable_not_allowed'));
        $this->assertTrue($owner->fresh()->hasMfaEnabled());
    }

    public function test_while_no_rule_requires_it_a_super_admin_may_turn_their_own_off(): void
    {
        // The staged roll-out: nothing is forced on the owner before they decide (docs/MFA_SECURITY.md, "Owner roll-out").
        $owner = $this->superAdmin();
        ['secret' => $secret] = $this->enrol($owner);
        $this->signInWithCode($owner, $secret);

        $this->assertTrue(MfaPolicy::canDisable($owner->fresh()));
        $this->post(route('admin.account.security.disable'), ['password' => self::PASSWORD, 'code' => $this->codeFor($secret)])->assertSessionHas('success');
    }

    // The per-role switch.
    public function test_requiring_it_for_a_role_needs_a_recent_password_and_records_who_is_now_affected(): void
    {
        $admin = $this->superAdmin();
        $role = $this->roleWith(['activities.view']);
        $member = $this->staffWithRole($role);

        $this->actingAs($admin)->patch(route('admin.roles.mfa', $role), ['mfa_required' => '1'])->assertRedirect(route('password.confirm'));
        $this->assertFalse((bool) $role->fresh()->mfa_required);
        $this->assertSame(route('admin.roles.edit', $role), session('url.intended'), 'After confirming, the admin returns to the role screen.');

        $this->withRecentPassword()->patch(route('admin.roles.mfa', $role), ['mfa_required' => '1'])
            ->assertRedirect(route('admin.roles.edit', $role))->assertSessionHas('success');
        $this->assertTrue((bool) $role->fresh()->mfa_required);

        $this->assertDatabaseHas('security_events', ['user_id' => $admin->id, 'event' => SecurityEvent::MFA_ROLE_REQUIREMENT_CHANGED]);
        $this->assertDatabaseHas('security_events', ['user_id' => $member->id, 'actor_id' => $admin->id, 'event' => SecurityEvent::MFA_NEWLY_REQUIRED]);
        $this->actingAs($member->fresh())->get(route('admin.dashboard'))->assertRedirect(route('admin.account.security'));
    }

    public function test_only_an_administrator_who_uses_it_with_a_recent_code_can_lift_a_role_requirement(): void
    {
        $role = $this->roleWith(['activities.view'], mfaRequired: true);

        $withoutMfa = $this->superAdmin();
        $this->actingAs($withoutMfa)->withRecentPassword()->patch(route('admin.roles.mfa', $role), ['mfa_required' => '0'])
            ->assertSessionHas('error', __('admin.mfa.role_off_needs_mfa'));
        $this->assertTrue((bool) $role->fresh()->mfa_required);

        $withMfa = $this->superAdmin();
        ['secret' => $secret] = $this->enrol($withMfa);
        $this->post('/logout');
        $this->signInWithCode($withMfa, $secret);
        $this->travel(16)->minutes();
        $this->patch(route('admin.roles.mfa', $role), ['mfa_required' => '0'])->assertRedirect(route('admin.account.security.step-up'));
        $this->assertTrue((bool) $role->fresh()->mfa_required);

        $this->post(route('admin.account.security.step-up.verify'), ['code' => $this->codeFor($secret)])->assertRedirect(route('admin.roles.edit', $role));
        $this->patch(route('admin.roles.mfa', $role), ['mfa_required' => '0'])->assertSessionHas('success');
        $this->assertFalse((bool) $role->fresh()->mfa_required);
    }

    public function test_only_a_super_admin_decides_the_super_admin_roles_requirement(): void
    {
        $superAdminRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $roleManager = $this->staffWithRole($this->roleWith(['users.view', 'users.update']));

        $this->actingAs($roleManager)->withRecentPassword()->patch(route('admin.roles.mfa', $superAdminRole), ['mfa_required' => '1'])
            ->assertSessionHas('error', __('admin.mfa.role_super_admin_only'));
        $this->assertFalse((bool) $superAdminRole->fresh()->mfa_required);
        $this->get(route('admin.roles.edit', $superAdminRole))->assertOk()->assertDontSee('id="role-mfa-required"', false);
    }

    public function test_the_role_form_cannot_change_the_requirement_on_the_side(): void
    {
        $admin = $this->superAdmin();
        $role = $this->roleWith(['activities.view'], mfaRequired: true);

        $this->actingAs($admin)->put(route('admin.roles.update', $role), ['name' => $role->name, 'mfa_required' => '0', 'permissions' => []])
            ->assertSessionHasNoErrors();
        $this->assertTrue((bool) $role->fresh()->mfa_required);
    }

    public function test_a_role_without_the_permission_to_edit_roles_cannot_use_the_switch(): void
    {
        $role = $this->roleWith(['activities.view'], mfaRequired: true);
        $editor = $this->staffWithRole($this->roleWith(['activities.view', 'activities.update']));

        $this->actingAs($editor)->withRecentPassword()->patch(route('admin.roles.mfa', $role), ['mfa_required' => '0'])->assertForbidden();
        $this->assertTrue((bool) $role->fresh()->mfa_required);
    }

    // The privileged-account rule.
    public function test_the_privileged_rule_is_switched_only_by_a_super_admin_with_a_recent_code(): void
    {
        $owner = $this->superAdmin();
        $this->actingAs($owner)->post(route('admin.account.security.enforcement'), ['enabled' => '1'])
            ->assertRedirect(route('admin.account.security'))->assertSessionHas('warning', __('admin.mfa.step_up_needs_mfa'));
        $this->assertFalse(MfaPolicy::privilegedEnforcementActive());

        $manager = $this->staffWithRole($this->roleWith(['users.view', 'users.update']));
        ['secret' => $managerSecret] = $this->enrol($manager);
        $this->post('/logout');
        $this->signInWithCode($manager, $managerSecret);
        $this->post(route('admin.account.security.enforcement'), ['enabled' => '1'])->assertForbidden();
        $this->assertFalse(MfaPolicy::privilegedEnforcementActive());
        $this->post('/logout');

        ['secret' => $secret] = $this->enrol($owner);
        $this->signInWithCode($owner, $secret);
        $this->post(route('admin.account.security.enforcement'), ['enabled' => '1'])->assertSessionHas('success');
        $this->assertTrue(MfaPolicy::privilegedEnforcementActive());
        $this->assertFalse((bool) Setting::query()->where('key', MfaPolicy::ENFORCEMENT_SETTING)->value('is_public'));
        $this->assertDatabaseHas('security_events', ['user_id' => $owner->id, 'event' => SecurityEvent::MFA_PRIVILEGED_ENFORCEMENT_CHANGED]);
    }

    public function test_with_the_rule_on_every_privileged_account_must_enrol_including_future_roles(): void
    {
        Setting::query()->create(['key' => MfaPolicy::ENFORCEMENT_SETTING, 'value' => 'true', 'value_type' => 'boolean', 'group_name' => 'security', 'is_public' => false]);

        $superAdmin = $this->superAdmin();
        $treasurer = $this->staffWithRole($this->roleWith(['payments.view', 'payments.approve'], name: 'Treasurer'));
        $mailbox = $this->staffWithRole($this->roleWith(['mail.manage'], name: 'Mail'));
        $settings = $this->staffWithRole($this->roleWith(['settings.update'], name: 'Settings'));
        $editor = $this->staffWithRole($this->roleWith(['activities.view', 'activities.update'], name: 'Editor'));

        foreach ([$superAdmin, $treasurer, $mailbox, $settings] as $privileged) {
            $this->assertTrue(MfaPolicy::mustEnrollNow($privileged), "{$privileged->email} should have to enrol");
        }
        $this->assertFalse(MfaPolicy::isRequired($editor), 'An account without a privileged capability stays optional.');

        $this->actingAs($treasurer)->get(route('admin.dashboard'))->assertRedirect(route('admin.account.security'));
        $this->actingAs($editor)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_the_policy_never_looks_at_a_user_id(): void
    {
        $source = (string) file_get_contents(app_path('Support/Mfa/MfaPolicy.php'));
        $this->assertDoesNotMatchRegularExpression('/->id\s*===?|getKey\(\)\s*===?|user_id/', $source);
    }
}
