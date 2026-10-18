<?php

namespace Tests\Feature\Recruitment;

use App\Jobs\SendVolunteerApplicationNotifications;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Notice;
use App\Models\Setting;
use App\Notifications\VolunteerApplicationReceivedNotification;
use App\Support\Recruitment\CentralMessengerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * No group invite is public (docs/VOLUNTEER_MESSENGER_GROUP.md): not the central Messenger invite, and not the WhatsApp
 * community link a recruitment notice's button used to carry. Every public API an outside reader (or the public site)
 * can ask is swept below with both configured. Tokens are made up.
 */
class PublicSurfaceHidesGroupInvitesTest extends TestCase
{
    use RefreshDatabase;

    private const INVITE_CODE = 'CentralTok3n-Q7w8E9';

    private const WHATSAPP_URL = 'https://chat.whatsapp.com/FakeWhatsAppToken123456';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function posting(): JobPosting
    {
        return JobPosting::query()->create([
            'title' => 'স্বেচ্ছাসেবী আহ্বান', 'slug' => 'volunteer-call-'.uniqid(), 'description' => 'বিবরণ',
            'employment_type' => 'volunteer', 'application_mode' => 'rolling', 'accepts_applications' => true, 'status' => 'open',
            'published_at' => now()->subDay(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function notice(array $attributes = []): Notice
    {
        return Notice::query()->create(array_merge([
            'title' => 'নোটিশ '.uniqid(), 'slug' => 'notice-'.uniqid(), 'notice_type' => 'volunteer', 'body' => 'বিবরণ',
            'status' => 'published', 'published_at' => now()->subHour(),
        ], $attributes));
    }

    private function configureMessenger(bool $enabled = true): void
    {
        Setting::query()->updateOrCreate(['key' => CentralMessengerGroup::KEY_ENABLED], ['value' => $enabled ? 'true' : 'false', 'value_type' => 'boolean', 'group_name' => 'recruitment', 'is_public' => false]);
        Setting::query()->updateOrCreate(['key' => CentralMessengerGroup::KEY_URL], ['value' => 'https://m.me/j/'.self::INVITE_CODE.'/', 'value_type' => 'string', 'group_name' => 'recruitment', 'is_public' => false]);
    }

    // --- The recruitment contract ------------------------------------------------------------------------------------

    public function test_a_posting_whose_notice_has_a_whatsapp_button_exposes_no_group_link(): void
    {
        $posting = $this->posting();
        $this->notice(['job_posting_id' => $posting->id, 'action_url' => self::WHATSAPP_URL, 'action_label' => 'WhatsApp Group-এ যুক্ত হোন']);
        $this->configureMessenger();

        foreach (["/api/v1/job-postings/{$posting->slug}", '/api/v1/job-postings'] as $url) {
            $response = $this->getJson($url)->assertOk();
            $this->assertStringNotContainsString('FakeWhatsAppToken', (string) $response->getContent(), $url);
            $this->assertStringNotContainsString('whatsapp', strtolower((string) $response->getContent()), $url);
            $this->assertStringNotContainsString(self::INVITE_CODE, (string) $response->getContent(), $url);
        }

        // The key is kept (always null) so a public site built before this change still reads the contract.
        $this->getJson("/api/v1/job-postings/{$posting->slug}")->assertJsonPath('data.notice_action', null);
        $this->assertSame(['notice_action' => null], ['notice_action' => $this->getJson('/api/v1/job-postings')->json('data.0.notice_action')]);
    }

    public function test_a_notice_linked_to_a_posting_hides_its_button_but_a_stand_alone_notice_keeps_it(): void
    {
        $posting = $this->posting();
        $linked = $this->notice(['job_posting_id' => $posting->id, 'action_url' => self::WHATSAPP_URL, 'action_label' => 'যুক্ত হোন']);
        $standalone = $this->notice(['action_url' => 'https://example.org/register', 'action_label' => 'নিবন্ধন করুন', 'action_label_en' => 'Register', 'notice_type' => 'event']);

        $this->getJson("/api/v1/public/notices/{$linked->slug}")->assertOk()
            ->assertJsonPath('data.action', null)
            ->assertJsonPath('data.recruitment.apply_path', "/recruitment/{$posting->slug}/apply");
        $this->assertStringNotContainsString('FakeWhatsAppToken', (string) $this->getJson("/api/v1/public/notices/{$linked->slug}")->getContent());

        $this->getJson("/api/v1/public/notices/{$standalone->slug}")->assertOk()
            ->assertJsonPath('data.action.url', 'https://example.org/register')
            ->assertJsonPath('data.action.label', 'নিবন্ধন করুন')
            ->assertJsonPath('data.action.label_en', 'Register');
    }

    public function test_a_notice_button_that_is_the_central_invite_is_never_published_wherever_it_came_from(): void
    {
        $this->configureMessenger();
        // Written below the admin form (which refuses it): the output refuses it too.
        $standalone = $this->notice(['action_url' => 'https://www.messenger.com/j/'.self::INVITE_CODE.'/?send_source=gc%3Acopy_invite_link_c', 'action_label' => 'যুক্ত হোন']);

        $this->getJson("/api/v1/public/notices/{$standalone->slug}")->assertOk()->assertJsonPath('data.action', null);
    }

    public function test_the_central_invite_is_in_no_public_response_even_when_configured(): void
    {
        $posting = $this->posting();
        $notice = $this->notice(['job_posting_id' => $posting->id, 'action_url' => self::WHATSAPP_URL, 'action_label' => 'x']);
        $this->configureMessenger();

        $paths = ["/api/v1/job-postings/{$posting->slug}", "/api/v1/public/notices/{$notice->slug}"];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/v1/') || str_contains($uri, '{') || str_contains($uri, 'auth/') || str_contains($uri, 'admin/') || str_contains($uri, 'member/')) {
                continue;
            }
            $paths[] = '/'.$uri;
        }
        $paths = array_values(array_unique($paths));
        $this->assertGreaterThan(10, count($paths), 'the sweep found too few routes: it is not protecting anything');

        foreach ($paths as $path) {
            $response = $this->getJson($path);
            $this->assertLessThan(500, $response->getStatusCode(), $path);
            foreach ([self::INVITE_CODE, 'FakeWhatsAppToken', 'm.me\\/j', 'messenger.com\\/j', 'central_messenger'] as $needle) {
                $this->assertStringNotContainsString($needle, (string) $response->getContent(), "{$path} leaks {$needle}");
            }
        }
    }

    // --- The applicant's receipt -------------------------------------------------------------------------------------

    public function test_the_receipt_carries_no_group_link_of_any_kind(): void
    {
        $posting = $this->posting();
        $this->notice(['job_posting_id' => $posting->id, 'action_url' => self::WHATSAPP_URL, 'action_label' => 'WhatsApp Group-এ যুক্ত হোন']);
        $this->configureMessenger();
        $application = JobApplication::query()->create([
            'application_no' => 'VOL-2026-0001', 'job_posting_id' => $posting->id, 'applicant_name' => 'নাদিয়া', 'applicant_email' => 'nadia@example.test',
            'applicant_phone' => '+8801711223344', 'accuracy_declaration' => true, 'privacy_consent' => true, 'contact_consent' => true,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);

        (new SendVolunteerApplicationNotifications($application->id))->handle();

        $message = Mail::mailer()->getSymfonyTransport()->messages()->first(fn ($sent) => in_array('nadia@example.test', array_map(fn ($a) => $a->getAddress(), $sent->getOriginalMessage()->getTo()), true));
        $this->assertNotNull($message, 'the receipt was not sent');
        /** @var Email $email */
        $email = $message->getOriginalMessage();
        $whole = $email->toString();
        $html = html_entity_decode((string) $email->getHtmlBody());
        $text = (string) $email->getTextBody();

        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('VOL-2026-0001', $body, 'the receipt still carries the application number');
            foreach (['whatsapp', 'FakeWhatsAppToken', self::INVITE_CODE, 'm.me', 'messenger', 'গ্রুপ', 'কমিউনিটি'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $body, "the receipt mentions {$needle}");
            }
        }
        $this->assertStringNotContainsString(self::INVITE_CODE, $whole);
        $this->assertStringNotContainsString('FakeWhatsAppToken', $whole);
    }

    public function test_the_receipt_notification_no_longer_takes_a_group_url(): void
    {
        $constructor = (new \ReflectionClass(VolunteerApplicationReceivedNotification::class))->getConstructor();

        $this->assertSame(['applicationNo', 'postingTitle'], array_map(fn ($p) => $p->getName(), $constructor->getParameters()));
        Notification::fake();
    }
}
