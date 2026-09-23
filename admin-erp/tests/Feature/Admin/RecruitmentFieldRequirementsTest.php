<?php

namespace Tests\Feature\Admin;

use App\Models\JobPosting;

/**
 * Application Form Settings: per-posting Required/Optional configuration for
 * the volunteer application form's non-core fields. Covers the admin side
 * only — JobPosting::resolvedFieldRequirements()/isFieldRequired() and the
 * generated public-form validation are covered in
 * Tests\Feature\Api\VolunteerApplicationFieldRequirementsTest.
 */
class RecruitmentFieldRequirementsTest extends AdminTestCase
{
    /**
     * Slug is included so this doubles as a direct JobPosting::create()
     * payload (which, unlike the admin controller, never auto-generates
     * one) as well as a form POST/PUT payload (where the controller's own
     * uniqueSlug() logic quietly overrides it as usual).
     */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'স্বেচ্ছাসেবী আহ্বান',
            'slug' => 'volunteer-'.uniqid(),
            'description' => 'বিবরণ',
            'employment_type' => 'volunteer',
            'application_mode' => 'rolling',
            'status' => 'open',
            'accepts_applications' => '1',
        ], $overrides);
    }

    public function test_an_existing_posting_with_no_stored_configuration_resolves_to_the_defaults(): void
    {
        // Every posting that existed before this feature shipped — field_requirements is NULL.
        $posting = JobPosting::query()->create($this->basePayload());
        $this->assertNull($posting->field_requirements);

        // Exactly what VolunteerApplicationController::rules() hardcoded before this feature —
        // shipping the migration changes no existing posting's real-world behaviour.
        $this->assertSame(JobPosting::DEFAULT_FIELD_REQUIREMENTS, $posting->resolvedFieldRequirements());
        $this->assertFalse($posting->isFieldRequired('photo'));
        $this->assertTrue($posting->isFieldRequired('experience'));
    }

    public function test_an_admin_can_set_field_requirements_when_creating_a_posting(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->basePayload([
            'field_requirements' => ['photo' => 'required', 'cv' => 'required', 'availability' => 'required'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $posting = JobPosting::query()->firstOrFail();
        $this->assertTrue($posting->isFieldRequired('photo'));
        $this->assertTrue($posting->isFieldRequired('cv'));
        $this->assertTrue($posting->isFieldRequired('availability'));
        // Untouched keys still fall back to the class default.
        $this->assertTrue($posting->isFieldRequired('experience'));
        $this->assertFalse($posting->isFieldRequired('preferred_contact'));
    }

    public function test_configuration_persists_independently_per_posting(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->basePayload([
            'title' => 'বিজ্ঞপ্তি ক',
            'field_requirements' => ['photo' => 'required'],
        ]))->assertRedirect();

        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->basePayload([
            'title' => 'বিজ্ঞপ্তি খ',
            'field_requirements' => ['photo' => 'optional', 'cv' => 'required'],
        ]))->assertRedirect();

        $a = JobPosting::query()->where('title', 'বিজ্ঞপ্তি ক')->firstOrFail();
        $b = JobPosting::query()->where('title', 'বিজ্ঞপ্তি খ')->firstOrFail();

        $this->assertTrue($a->isFieldRequired('photo'));
        $this->assertFalse($a->isFieldRequired('cv'));

        $this->assertFalse($b->isFieldRequired('photo'));
        $this->assertTrue($b->isFieldRequired('cv'));
    }

    public function test_an_admin_can_change_field_requirements_on_update(): void
    {
        $admin = $this->superAdmin();
        $posting = JobPosting::query()->create($this->basePayload());
        $this->assertFalse($posting->isFieldRequired('photo'));

        $this->actingAs($admin)->put(route('admin.recruitment.update', $posting), $this->basePayload([
            'field_requirements' => ['photo' => 'required', 'cv' => 'optional', 'availability' => 'optional'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $posting->refresh();
        $this->assertTrue($posting->isFieldRequired('photo'));
        $this->assertFalse($posting->isFieldRequired('cv'));
        $this->assertFalse($posting->isFieldRequired('availability'));
    }

    public function test_an_unknown_field_key_is_never_stored(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->basePayload([
            // applicant_name is deliberately not configurable — see JobPosting's own
            // docblock on CONFIGURABLE_APPLICATION_FIELDS. A hand-crafted request
            // trying to smuggle it in must be silently dropped, not stored.
            'field_requirements' => ['photo' => 'required', 'applicant_name' => 'optional', 'made_up_field' => 'required'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $posting = JobPosting::query()->firstOrFail();
        $this->assertArrayNotHasKey('applicant_name', $posting->field_requirements);
        $this->assertArrayNotHasKey('made_up_field', $posting->field_requirements);
        $this->assertTrue($posting->isFieldRequired('photo'));
    }

    public function test_an_invalid_requirement_value_fails_validation(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->basePayload([
            'field_requirements' => ['photo' => 'mandatory-ish'],
        ]))->assertSessionHasErrors('field_requirements.photo');

        $this->assertSame(0, JobPosting::query()->count());
    }

    public function test_the_settings_card_shows_the_current_configuration_on_the_edit_form(): void
    {
        $posting = JobPosting::query()->create($this->basePayload([
            'field_requirements' => ['photo' => 'required'],
        ]));

        $this->actingAs($this->superAdmin())->get(route('admin.recruitment.edit', $posting))
            ->assertOk()
            ->assertSee('আবেদন ফরমের ফিল্ড সেটিংস')
            ->assertSee('প্রোফাইল ছবি');
    }
}
