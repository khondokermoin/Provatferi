<?php

namespace Tests\Feature\Mail;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\CommitteeCorrectionRequestedNotification;
use App\Notifications\CommitteeSubmissionStatusChangedNotification;
use App\Notifications\MemberInvitationNotification;
use App\Notifications\MembershipApplicationStatusChangedNotification;
use App\Notifications\MemberSetPasswordNotification;
use App\Notifications\MfaSecurityNotification;
use App\Notifications\PublicProfileReviewedNotification;
use App\Notifications\VolunteerApplicationReceivedNotification;
use App\Notifications\VolunteerApplicationStatusChangedNotification;
use App\Notifications\VolunteerApplicationSubmittedNotification;
use App\Support\InstitutionMail;
use Closure;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Throwable;

/**
 * The e-mail role architecture (docs/EMAIL_ARCHITECTURE.md). Five mailboxes, four concepts kept apart: the SMTP login
 * (admin@, internal), the visible From (no-reply@), the Reply-To (support@, or security@ for security mail) and the
 * public contact (info@). Header assertions read the Symfony message handed to the transport (MAIL_MAILER=array in
 * phpunit.xml), so a global mailer or config change cannot slip past them.
 */
class EmailIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC = 'info@provatferi.org';

    private const SUPPORT = 'support@provatferi.org';

    private const SECURITY = 'security@provatferi.org';

    private const NOREPLY = 'no-reply@provatferi.org';

    private const INTERNAL = 'admin@provatferi.org';

    private const SENDER_NAME = 'Provatferi Literary and Cultural Center';

    // --- The canonical configuration layer -------------------------------------------------------------------------

    public function test_every_role_resolves_to_its_official_mailbox(): void
    {
        $this->assertSame(self::PUBLIC, config('institution.email.public'));
        $this->assertSame(self::SUPPORT, config('institution.email.support'));
        $this->assertSame(self::SECURITY, config('institution.email.security'));
        $this->assertSame(self::NOREPLY, config('institution.email.noreply'));
        $this->assertSame(self::INTERNAL, config('institution.email.internal'));
    }

    public function test_the_visible_from_is_the_no_reply_role_and_the_public_contact_is_never_the_sender(): void
    {
        $this->assertSame(config('institution.email.noreply'), config('mail.from.address'));
        $this->assertSame(self::SENDER_NAME, config('mail.from.name'));
        $this->assertNotSame(config('institution.email.public'), config('mail.from.address'));
    }

    public function test_the_shipped_configuration_has_no_problems(): void
    {
        $this->assertSame([], InstitutionMail::problems());
    }

    public function test_the_old_mixed_reply_to_block_is_gone(): void
    {
        // `general` made info@ an automated Reply-To and `operations` stored a recipient under "reply_to".
        $this->assertNull(config('mail.reply_to'));
    }

    public function test_from_and_the_noreply_role_read_the_same_variables_so_they_cannot_drift(): void
    {
        $resolve = fn (): array => [(require config_path('mail.php'))['from']['address'], (require config_path('institution.php'))['email']['noreply']];

        $this->withEnv(['INSTITUTION_NOREPLY_EMAIL' => 'sender@example.test', 'MAIL_FROM_ADDRESS' => 'older@example.test'], function () use ($resolve) {
            $this->assertSame(['sender@example.test', 'sender@example.test'], $resolve());
        });
        $this->withEnv(['INSTITUTION_NOREPLY_EMAIL' => null, 'MAIL_FROM_ADDRESS' => 'older@example.test'], function () use ($resolve) {
            $this->assertSame(['older@example.test', 'older@example.test'], $resolve());
        });
    }

    public function test_an_env_file_written_before_the_canonical_layer_still_resolves_the_same(): void
    {
        $this->withEnv([
            'INSTITUTION_SUPPORT_EMAIL' => null, 'MAIL_REPLY_TO_SUPPORT' => 'help@example.test',
            'INSTITUTION_SECURITY_EMAIL' => null, 'MAIL_REPLY_TO_SECURITY' => 'alerts@example.test',
            'INSTITUTION_INTERNAL_EMAIL' => null, 'MAIL_OPERATIONS_ADDRESS' => 'staff@example.test',
        ], function () {
            $email = (require config_path('institution.php'))['email'];
            $this->assertSame('help@example.test', $email['support']);
            $this->assertSame('alerts@example.test', $email['security']);
            $this->assertSame('staff@example.test', $email['internal']);
        });
    }

    public function test_the_smtp_login_is_its_own_role_read_from_mail_username(): void
    {
        $this->withEnv(['MAIL_USERNAME' => 'login@example.test'], function () {
            $this->assertSame('login@example.test', (require config_path('institution.php'))['email']['smtp_auth']);
        });
    }

    // --- Every message the application sends ---------------------------------------------------------------------

    /** @return array<string, array{0: Closure(): BaseNotification, 1: string}> */
    public static function applicationMessages(): array
    {
        return [
            'member password reset' => [fn () => new MemberSetPasswordNotification('https://provatferi.org/member/reset-password?token=t&email=e', 60), 'support'],
            'member portal invitation' => [fn () => new MemberInvitationNotification('https://provatferi.org/member/reset-password?token=t&email=e', 60, 'PLCC-LM-2026-0001'), 'support'],
            'membership approved' => [fn () => new MembershipApplicationStatusChangedNotification('APP-2026-0001', 'approved', null, 'PLCC-LM-2026-0001', 'invited'), 'support'],
            'membership rejected' => [fn () => new MembershipApplicationStatusChangedNotification('APP-2026-0001', 'rejected', 'কারণ'), 'support'],
            'membership needs information' => [fn () => new MembershipApplicationStatusChangedNotification('APP-2026-0001', 'need_information', 'ছবি'), 'support'],
            'volunteer application received' => [fn () => new VolunteerApplicationReceivedNotification('VOL-2026-0001', 'স্বেচ্ছাসেবক'), 'support'],
            'volunteer application decided' => [fn () => new VolunteerApplicationStatusChangedNotification('VOL-2026-0001', 'accepted', 'নাদিয়া', 'স্বেচ্ছাসেবক'), 'support'],
            'committee correction requested' => [fn () => new CommitteeCorrectionRequestedNotification('কার্যনির্বাহী কমিটি', 'ছবি অস্পষ্ট', 'https://provatferi.org/committee/register/correct/t', 7), 'support'],
            'committee submission decided' => [fn () => new CommitteeSubmissionStatusChangedNotification('কার্যনির্বাহী কমিটি', 'approved'), 'support'],
            'public profile reviewed' => [fn () => new PublicProfileReviewedNotification('approved'), 'support'],
            'internal new-application notice' => [fn () => new VolunteerApplicationSubmittedNotification('VOL-2026-0001', 'স্বেচ্ছাসেবক', 'নাদিয়া', 'https://admin.provatferi.org/admin/recruitment-applications/1'), 'internal'],
            'two-step verification turned on' => [fn () => new MfaSecurityNotification('enabled', ['at' => '17 Oct 2026, 12:00 (Asia/Dhaka)', 'ip' => '203.0.113.5', 'device' => 'Chrome on Windows']), 'security'],
            'two-step verification reset on the server' => [fn () => new MfaSecurityNotification('reset', ['at' => '17 Oct 2026, 12:00 (Asia/Dhaka)']), 'security'],
        ];
    }

    #[DataProvider('applicationMessages')]
    public function test_each_message_leaves_from_no_reply_with_the_reply_to_of_its_class(Closure $make, string $class): void
    {
        Notification::route('mail', 'person@example.test')->notify($make());
        $email = $this->lastSentEmail();

        $this->assertSame([[self::NOREPLY, self::SENDER_NAME]], $this->addresses($email->getFrom()));
        $this->assertSame(['person@example.test'], array_column($this->addresses($email->getTo()), 0));

        $expectedReplyTo = match ($class) {
            'support' => [[self::SUPPORT, 'Provatferi Support']],
            'security' => [[self::SECURITY, 'Provatferi Security']],
            'internal' => [],
        };
        $this->assertSame($expectedReplyTo, $this->addresses($email->getReplyTo()));
        $this->assertNoPrivateMailboxIn($email);
    }

    public function test_every_notification_class_in_the_application_is_classified_above(): void
    {
        // A new workflow must choose its class (support / security / internal) — this fails until it is listed.
        $covered = array_map(fn (array $row) => $row[0]()::class, self::applicationMessages());
        foreach (glob(app_path('Notifications/*.php')) as $file) {
            $class = 'App\\Notifications\\'.basename($file, '.php');
            $this->assertContains($class, $covered, "{$class} is not covered by applicationMessages(): decide its mail class (docs/EMAIL_ARCHITECTURE.md).");
            $this->assertMatchesRegularExpression(
                '/InstitutionMail::(support|security|internal)\(/',
                (string) file_get_contents($file),
                "{$class} must set its identity headers through App\\Support\\InstitutionMail.",
            );
        }
    }

    public function test_the_staff_password_reset_is_a_support_message(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        $email = $this->lastSentEmail();

        $this->assertSame([[self::NOREPLY, self::SENDER_NAME]], $this->addresses($email->getFrom()));
        $this->assertSame([[self::SUPPORT, 'Provatferi Support']], $this->addresses($email->getReplyTo()));
        $this->assertNoPrivateMailboxIn($email);
    }

    public function test_the_security_class_replies_to_security_and_still_sends_from_no_reply(): void
    {
        Notification::route('mail', 'person@example.test')->notify($this->messageOf(fn () => InstitutionMail::security((new MailMessage)->subject('New sign-in')->line('A sign-in happened.'))));
        $email = $this->lastSentEmail();

        $this->assertSame([[self::NOREPLY, self::SENDER_NAME]], $this->addresses($email->getFrom()));
        $this->assertSame([[self::SECURITY, 'Provatferi Security']], $this->addresses($email->getReplyTo()));
    }

    public function test_the_internal_notice_is_delivered_to_the_internal_role_only(): void
    {
        $this->assertSame(self::INTERNAL, InstitutionMail::internalRecipient());

        config(['institution.email.internal' => 'not an address']);
        $this->assertNull(InstitutionMail::internalRecipient(), 'A malformed internal mailbox means no notice, never a guess.');
    }

    public function test_no_application_code_sets_identity_headers_or_names_a_mailbox_outside_the_canonical_layer(): void
    {
        $offenders = [];
        foreach ([app_path(), resource_path('views'), base_path('routes')] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = $file->getPathname();
                if (! str_ends_with($path, '.php') || str_ends_with($path, 'InstitutionMail.php')) {
                    continue;
                }
                $code = $this->codeWithoutComments((string) file_get_contents($path));
                if (preg_match('/[A-Za-z0-9._%+-]+@provatferi\.(org|test)/', $code, $m)) {
                    $offenders[] = basename($path).": literal {$m[0]}";
                }
                if (preg_match('/->(replyTo|from|sender)\(|config\([\'"]mail\.reply_to/', $code, $m)) {
                    $offenders[] = basename($path).": {$m[0]}";
                }
            }
        }

        $this->assertSame([], $offenders, 'Identity headers and addresses belong to config/institution.php and App\Support\InstitutionMail only.');
    }

    // --- Header injection ------------------------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function headerInjections(): array
    {
        return [
            'CRLF' => ["victim@example.com\r\nBcc: attacker@example.net"],
            'LF' => ["victim@example.com\nBcc: attacker@example.net"],
            'CR' => ["victim@example.com\rBcc: attacker@example.net"],
            'folded' => ["victim@example.com\r\n Bcc: attacker@example.net"],
            'in a display name' => ["\"Victim\r\nBcc: attacker@example.net\" <victim@example.com>"],
        ];
    }

    #[DataProvider('headerInjections')]
    public function test_a_recipient_with_a_line_break_is_refused_where_it_enters_and_by_the_mailer(string $value): void
    {
        foreach (['email', 'email:rfc'] as $rule) {
            $this->assertTrue(Validator::make(['e' => $value], ['e' => $rule])->fails(), "the '{$rule}' rule accepted a multi-line address");
        }

        try {
            new Address($value);
            $this->fail('Symfony accepted a multi-line address');
        } catch (Throwable $e) {
            $this->assertStringContainsString('Mime', $e::class);
        }
    }

    #[DataProvider('headerInjections')]
    public function test_a_role_address_with_a_line_break_is_never_used_and_is_reported(string $value): void
    {
        config(['institution.email.support' => $value]);

        $this->assertNull(InstitutionMail::address('support'));
        $this->assertContains('institution.email.support is not exactly one plain e-mail address', InstitutionMail::problems());

        Notification::route('mail', 'person@example.test')->notify(new PublicProfileReviewedNotification('approved'));
        $this->assertSame([], $this->lastSentEmail()->getReplyTo(), 'A bad support address costs the Reply-To header, not the message.');
    }

    public function test_a_subject_with_line_breaks_cannot_add_a_header(): void
    {
        Notification::route('mail', 'victim@example.test')->notify(new CommitteeSubmissionStatusChangedNotification("কমিটি\r\nBcc: attacker@example.net\r\nX-Injected: yes", 'approved'));

        $email = $this->lastSentEmail();
        $raw = $email->toString();
        $headers = substr($raw, 0, (int) strpos($raw, "\r\n\r\n"));

        $this->assertDoesNotMatchRegularExpression('/^(Bcc|X-Injected):/mi', $headers);
        $this->assertSame(['victim@example.test'], array_map(fn (Address $a) => $a->getAddress(), $email->getTo()));
        $this->assertStringNotContainsString("\n", (string) $email->getSubject());
        $this->assertStringContainsString('কমিটি Bcc: attacker@example.net X-Injected: yes', (string) $email->getSubject());
    }

    // --- admin@ stays private ---------------------------------------------------------------------------------------

    public function test_a_role_pointed_at_a_private_mailbox_is_left_out_and_reported(): void
    {
        config(['institution.email.support' => self::INTERNAL]);

        Notification::route('mail', 'person@example.test')->notify(new PublicProfileReviewedNotification('approved'));

        $this->assertSame([], $this->lastSentEmail()->getReplyTo());
        $this->assertContains('the support Reply-To (institution.email.support) is a private mailbox (internal or SMTP authentication)', InstitutionMail::problems());
    }

    public function test_the_smtp_login_mailbox_is_private_unless_it_is_the_sender_itself(): void
    {
        config(['institution.email.smtp_auth' => 'Login@Example.test']);
        $this->assertTrue(InstitutionMail::isPrivate('login@example.test'));
        $this->assertTrue(InstitutionMail::isPrivate('ADMIN@provatferi.org'));
        $this->assertFalse(InstitutionMail::isPrivate(self::SUPPORT));

        config(['institution.email.smtp_auth' => self::NOREPLY]);
        $this->assertFalse(InstitutionMail::isPrivate(self::NOREPLY), 'Logging in as the sender itself exposes nothing new.');
    }

    public function test_the_settings_screen_refuses_to_publish_a_private_mailbox(): void
    {
        $this->seed([\Database\Seeders\RolesAndPermissionsSeeder::class, SiteContentSeeder::class]);
        $admin = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
        $admin->roles()->attach(\App\Models\Role::query()->where('slug', 'super_admin')->firstOrFail());

        // Field names as PHP delivers them from a browser (underscores; see App\Support\SiteSettings).
        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'site_name_bn' => 'X', 'site_name_en' => 'X', 'site_short_name' => 'X',
            'site_email' => 'Admin@Provatferi.org', 'site_support_email' => 'support@provatferi.org',
        ])->assertSessionHasErrors('site_email');

        $this->assertSame(self::PUBLIC, Setting::get('site.email'));
    }

    public function test_public_pages_and_apis_never_show_a_private_mailbox(): void
    {
        $this->seed(SiteContentSeeder::class);
        // Production has a staff account on the internal mailbox: a public endpoint that serialised users would leak it.
        User::factory()->create(['email' => self::INTERNAL, 'status' => 'active']);
        config(['institution.email.smtp_auth' => 'smtp-login@example.test', 'mail.mailers.smtp.username' => 'smtp-login@example.test']);

        $checked = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $guarded = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && (str_contains($m, 'Authenticate') || str_starts_with($m, 'auth')));
            if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{') || $guarded) {
                continue;
            }
            $response = $this->get('/'.ltrim($uri, '/'));
            $this->assertLessThan(500, $response->getStatusCode(), "GET /{$uri}");
            foreach ([self::INTERNAL, 'smtp-login@example.test'] as $private) {
                $this->assertStringNotContainsStringIgnoringCase($private, (string) $response->getContent(), "GET /{$uri} shows a private mailbox");
            }
            $checked[] = $uri;
        }

        $notFound = $this->get('/a-page-that-does-not-exist');
        $this->assertStringNotContainsStringIgnoringCase(self::INTERNAL, (string) $notFound->getContent());
        $this->assertStringNotContainsStringIgnoringCase('smtp-login@example.test', (string) $notFound->getContent());

        $this->assertContains('api/v1/settings', $checked);
        $this->assertContains('login', $checked);
        $this->assertContains('forgot-password', $checked);
        $this->getJson('/api/v1/settings')->assertJsonPath('data.contact.email', self::PUBLIC);
    }

    public function test_client_side_code_names_no_mail_credential_and_no_private_mailbox(): void
    {
        $scanned = 0;
        $offenders = [];
        foreach ([resource_path('js'), public_path('js'), public_path('build')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! preg_match('/\.(js|mjs|ts|css|json)$/', $file->getFilename())) {
                    continue;
                }
                $scanned++;
                $text = (string) file_get_contents($file->getPathname());
                if (preg_match('/MAIL_(PASSWORD|USERNAME)|HOSTINGER_MAIL_API_TOKEN|smtp\.hostinger\.com|admin@provatferi\.org/i', $text, $m)) {
                    $offenders[] = $file->getFilename().': '.$m[0];
                }
            }
        }

        $this->assertGreaterThan(0, $scanned, 'No client-side file was scanned: the paths are wrong.');
        $this->assertSame([], $offenders);
    }

    public function test_the_identity_report_never_prints_the_smtp_password(): void
    {
        config(['mail.mailers.smtp.password' => 'a-secret-that-must-not-print-7Qx']);

        $this->artisan('mail:identity')
            ->doesntExpectOutputToContain('a-secret-that-must-not-print-7Qx')
            ->expectsOutputToContain('password set')
            ->assertSuccessful();
    }

    // --- Logs -------------------------------------------------------------------------------------------------------

    public function test_a_password_reset_writes_neither_its_token_nor_its_link_to_the_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        $user = User::factory()->create(['email' => 'staff@example.test']);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        $body = html_entity_decode((string) $this->lastSentEmail()->getHtmlBody(), ENT_QUOTES | ENT_HTML5);
        $this->assertSame(1, preg_match('#/reset-password/([A-Za-z0-9]+)#', $body, $m), 'the reset link is in the mail');

        foreach ($logged as $line) {
            $this->assertStringNotContainsString($m[1], $line, 'A reset token reached the log.');
        }
    }

    public function test_production_refuses_a_mailer_that_keeps_whole_messages(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log', 'institution.email.smtp_auth' => 'smtp-login@example.test']);

        $this->assertNotSame([], array_filter(InstitutionMail::problems(), fn (string $p) => str_contains($p, "'log' mailer")));
    }

    // --- Helpers ----------------------------------------------------------------------------------------------------

    private function lastSentEmail(): Email
    {
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages, 'No message reached the mail transport.');

        return $messages->last()->getOriginalMessage();
    }

    /** @param array<int, Address> $addresses @return list<array{0: string, 1: string}> */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $a) => [$a->getAddress(), $a->getName()], $addresses);
    }

    private function assertNoPrivateMailboxIn(Email $email): void
    {
        $headers = $email->getHeaders();
        foreach (['From', 'Reply-To', 'Sender', 'Return-Path'] as $name) {
            $this->assertStringNotContainsStringIgnoringCase(self::INTERNAL, (string) $headers->get($name)?->getBodyAsString(), "{$name} names the internal mailbox");
        }
        foreach (['HTML' => $email->getHtmlBody(), 'text' => $email->getTextBody()] as $part => $body) {
            $this->assertStringNotContainsStringIgnoringCase(self::INTERNAL, (string) $body, "the {$part} part names the internal mailbox");
            // Signed and branded with the organisation's public name, never the internal app's ("Provatferi ERP").
            $this->assertStringNotContainsString((string) config('app.name'), (string) $body, "the {$part} part shows the internal app name");
            $this->assertStringContainsString(self::SENDER_NAME, (string) $body, "the {$part} part does not carry the organisation's name");
        }
    }

    /** A one-off notification whose mail is the given message. */
    private function messageOf(Closure $build): BaseNotification
    {
        return new class($build) extends BaseNotification
        {
            public function __construct(private readonly Closure $build) {}

            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return ($this->build)();
            }
        };
    }

    /** PHP with its comments removed, so documentation may mention an address while code may not. */
    private function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        // Blade comments are not PHP tokens.
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $code);
    }

    /**
     * Runs $callback with environment variables set (a string) or removed (null), then restores them exactly. env()
     * reads $_SERVER, then $_ENV, then getenv(), so all three are set together.
     *
     * @param  array<string, string|null>  $vars
     */
    private function withEnv(array $vars, Closure $callback): void
    {
        $saved = [];
        foreach ($vars as $name => $value) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }

        try {
            $callback();
        } finally {
            foreach ($saved as $name => [$server, $env, $getenv]) {
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                $getenv === false ? putenv($name) : putenv("{$name}={$getenv}");
            }
        }
    }
}
