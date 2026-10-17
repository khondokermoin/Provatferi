<?php

namespace Tests\Feature\Mfa;

use App\Models\SecurityEvent;
use App\Services\SecurityEventLogger;
use App\Services\Mfa\TotpService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Symfony\Component\Mime\Email;

/**
 * Where the secret and the codes may never appear (docs/MFA_SECURITY.md, "What is never logged"): R logs and the audit
 * trail, S the security e-mails, T anything a browser or an API client receives after enrolment; plus storage at rest.
 */
class MfaSecretSafetyTest extends MfaTestCase
{
    /** @var list<string> */
    private array $logged = [];

    /** @var list<string> every code and secret this test handled */
    private array $material = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    // R + S — a whole life cycle through the forms, then every trace is searched.
    public function test_a_full_life_cycle_leaves_no_secret_or_code_in_logs_audit_rows_session_or_mail(): void
    {
        $password = 'Zq8-Unique-Pa55-word-71';
        $user = $this->staff(['password' => Hash::make($password)]);
        $this->material[] = $password;
        $this->actingAs($user)->withRecentPassword();

        $this->get(route('admin.account.security.setup'))->assertOk();
        $secret = $user->fresh()->mfa_pending_secret;
        $this->material[] = $secret;
        $this->material[] = TotpService::formatForDisplay($secret);

        $code = $this->codeFor($secret);
        $this->material[] = $code;
        $enabled = $this->post(route('admin.account.security.enable'), ['code' => $code])->assertOk();
        preg_match_all('/\b[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}\b/', (string) $enabled->getContent(), $m);
        $codes = array_values(array_unique($m[0]));
        array_push($this->material, ...$codes);
        $this->assertStringNotContainsString($secret, json_encode(session()->all()), 'The session must not keep the secret once it is on.');
        $this->nextStep();
        $this->post('/logout');

        // Sign in: a wrong code, then a right one.
        $this->passwordStep($user, password: $password);
        $this->post(route('mfa.challenge.verify'), ['code' => '000000']);
        $right = $this->codeFor($secret);
        $this->material[] = $right;
        $this->post(route('mfa.challenge.verify'), ['code' => $right])->assertSessionHasNoErrors();
        $this->nextStep();
        $this->post('/logout');

        // A recovery code, regeneration, then turning it off.
        $this->passwordStep($user, password: $password);
        $this->post(route('mfa.challenge.recovery'), ['recovery_code' => $codes[0]])->assertSessionHasNoErrors();
        $regenerated = $this->withRecentPassword()->post(route('admin.account.security.codes.regenerate'))->assertOk();
        preg_match_all('/\b[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}\b/', (string) $regenerated->getContent(), $m2);
        array_push($this->material, ...array_values(array_unique($m2[0])));
        $off = $this->codeFor($secret);
        $this->material[] = $off;
        $this->post(route('admin.account.security.disable'), ['password' => $password, 'code' => $off])->assertSessionHas('success');

        // R — logs and the audit trail.
        $audit = json_encode(DB::table('security_events')->get()->all());
        $this->assertNotEmpty(SecurityEvent::query()->where('user_id', $user->id)->get());
        foreach ($this->material as $needle) {
            foreach ($this->logged as $line) {
                $this->assertStringNotContainsString($needle, $line, 'A secret or code reached the log.');
            }
            $this->assertStringNotContainsString($needle, (string) $audit, 'A secret or code reached the audit trail.');
        }
        $this->assertStringNotContainsString('otpauth', (string) $audit);

        // S — the security e-mails: one per change, From no-reply@, Reply-To security@, and nothing secret inside.
        $mails = $this->sentMails();
        $subjects = array_map(fn (Email $e) => (string) $e->getSubject(), $mails);
        foreach (['subject_enabled', 'subject_code_used', 'subject_codes_regenerated', 'subject_disabled'] as $key) {
            $this->assertContains(trans('admin.mfa.mail.'.$key, [], 'en'), $subjects);
        }
        foreach ($mails as $mail) {
            $this->assertSame('no-reply@provatferi.org', $mail->getFrom()[0]->getAddress());
            $this->assertSame('security@provatferi.org', $mail->getReplyTo()[0]->getAddress());
            $whole = $mail->toString();
            foreach ($this->material as $needle) {
                $this->assertStringNotContainsString($needle, $whole, 'A security e-mail carries a secret or a code.');
            }
            $this->assertStringNotContainsString('otpauth', $whole);
            $this->assertStringNotContainsStringIgnoringCase('two-factor-challenge', $whole, 'A security e-mail must not carry a sign-in link.');
        }
    }

    public function test_a_security_email_is_written_in_the_persons_admin_language(): void
    {
        $person = $this->staff(['ui_locale' => 'bn']);
        $this->enrol($person);

        $last = $this->sentMails()[count($this->sentMails()) - 1];
        $this->assertSame(trans('admin.mfa.mail.subject_enabled', [], 'bn'), (string) $last->getSubject());
    }

    public function test_the_audit_trail_refuses_a_field_that_could_hold_a_credential(): void
    {
        $user = $this->staff();
        foreach (['secret', 'code', 'recovery_code', 'password', 'token', 'session_id', 'otp', 'uri', 'CODE'] as $key) {
            try {
                app(SecurityEventLogger::class)->record(SecurityEvent::MFA_ENABLED, $user, [$key => 'x']);
                $this->fail("The audit trail accepted \"{$key}\".");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_an_audit_row_records_who_when_where_and_which_browser(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->withRecentPassword()->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0')
            ->get(route('admin.account.security.setup'));

        $row = SecurityEvent::query()->where('event', SecurityEvent::MFA_ENROLLMENT_STARTED)->firstOrFail();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($user->id, $row->actor_id);
        $this->assertSame('203.0.113.9', $row->ip_address);
        $this->assertStringContainsString('Chrome', (string) $row->user_agent);
        $this->assertNotNull($row->created_at);
    }

    // Storage at rest.
    public function test_the_secret_is_encrypted_at_rest_and_the_pending_one_too(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->withRecentPassword()->get(route('admin.account.security.setup'));
        $pending = $user->fresh()->mfa_pending_secret;
        $rawPending = (string) DB::table('users')->where('id', $user->id)->value('mfa_pending_secret');
        $this->assertStringNotContainsString($pending, $rawPending);
        $this->assertSame($pending, Crypt::decryptString($rawPending));

        $this->post(route('admin.account.security.enable'), ['code' => $this->codeFor($pending)])->assertOk();
        $raw = (string) DB::table('users')->where('id', $user->id)->value('mfa_secret');
        $this->assertStringNotContainsString($pending, $raw);
        $this->assertSame($pending, Crypt::decryptString($raw));
    }

    // T — nothing reaches a browser or a client after enrolment.
    public function test_serialised_accounts_never_include_two_step_material(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);

        $json = $user->fresh()->toJson();
        foreach (['mfa_secret', 'mfa_pending_secret', 'mfa_recovery_codes', 'mfa_last_used_step', 'mfa_pending_created_at', $secret] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_another_admin_viewing_the_account_never_sees_its_secret(): void
    {
        $person = $this->staff();
        ['secret' => $secret, 'codes' => $codes] = $this->enrol($person);
        $admin = $this->superAdmin();

        foreach ([route('admin.users.index'), route('admin.users.show', $person), route('admin.users.edit', $person)] as $url) {
            $page = $this->actingAs($admin)->get($url)->assertOk();
            $page->assertDontSee($secret)->assertDontSee(TotpService::formatForDisplay($secret))->assertDontSee('otpauth', false);
            foreach ($codes as $code) {
                $page->assertDontSee($code);
            }
        }
    }

    public function test_public_endpoints_say_nothing_about_two_step(): void
    {
        $this->seed(\Database\Seeders\SiteContentSeeder::class);
        $owner = $this->superAdmin();
        $this->enrol($owner);

        foreach (['/api/v1/settings', '/login', '/forgot-password'] as $url) {
            $body = (string) $this->get($url)->getContent();
            $this->assertStringNotContainsString('mfa_privileged', $body);
            $this->assertStringNotContainsString('mfa_secret', $body);
        }
    }

    public function test_the_qr_code_is_drawn_on_the_server_and_no_client_code_handles_a_secret(): void
    {
        $user = $this->staff();
        $setup = $this->actingAs($user)->withRecentPassword()->get(route('admin.account.security.setup'))->assertOk();

        foreach (['chart.googleapis.com', 'api.qrserver.com', 'quickchart.io', 'qrcode.js', 'otpauth://'] as $outsider) {
            $this->assertStringNotContainsString($outsider, (string) $setup->getContent(), "The set-up page sends the secret to {$outsider}.");
        }

        $scanned = 0;
        foreach ([public_path('js'), public_path('build'), resource_path('js')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! preg_match('/\.(js|mjs|ts|json)$/', $file->getFilename())) {
                    continue;
                }
                $scanned++;
                $text = (string) file_get_contents($file->getPathname());
                $this->assertDoesNotMatchRegularExpression('/otpauth:|mfa_secret|mfa_recovery_codes|totp_secret/i', $text, $file->getFilename());
            }
        }
        $this->assertGreaterThan(0, $scanned);
    }

    /** @return list<Email> */
    private function sentMails(): array
    {
        return array_map(fn ($sent) => $sent->getOriginalMessage(), iterator_to_array(Mail::mailer()->getSymfonyTransport()->messages()));
    }
}
