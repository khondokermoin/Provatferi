<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The organisation runs five mailboxes with distinct roles. Automated mail must
 * leave from no-reply@, and a reset — the message a locked-out member is most
 * likely to reply to — must direct that reply to support@, not into a mailbox
 * nobody reads.
 *
 * The header assertions read the actual Symfony message handed to the transport
 * rather than the notification object, so they would catch a global mailer or
 * config change that a notification-level assertion would sail straight past.
 * Under `MAIL_MAILER=array` (see phpunit.xml) that message is everything the
 * real SMTP transport would receive, minus the network hop itself.
 */
class PasswordResetMailPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'the-old-password';

    private const NEW_PASSWORD = 'a-brand-new-password';

    // --- Configuration policy -------------------------------------------

    public function test_automated_mail_is_sent_from_the_no_reply_mailbox(): void
    {
        $this->assertSame('no-reply@provatferi.org', config('mail.from.address'));
        $this->assertSame('Provatferi Literary and Cultural Center', config('mail.from.name'));
    }

    public function test_the_public_identity_is_never_the_automated_sender(): void
    {
        $this->assertNotSame(
            'info@provatferi.org',
            config('mail.from.address'),
            'info@ is the public human contact and must not be the automated sender.',
        );
    }

    public function test_the_internal_admin_mailbox_is_not_used_for_member_facing_mail(): void
    {
        foreach (['from.address', 'reply_to.general', 'reply_to.support', 'reply_to.security'] as $key) {
            $this->assertNotSame(
                'admin@provatferi.org',
                config("mail.{$key}"),
                "admin@ is internal-only and must not appear in mail.{$key}.",
            );
        }
    }

    public function test_smtp_transport_is_configured_for_implicit_tls(): void
    {
        // Port 465 with the smtps scheme establishes TLS before the SMTP
        // conversation starts, so there is no cleartext window to downgrade.
        // Guards the documented production values in .env.example.
        $this->assertContains(config('mail.mailers.smtp.scheme'), [null, 'smtps'], 'STARTTLS on 587 is the fallback, not the default.');
    }

    // --- Delivery mechanics ---------------------------------------------

    public function test_requesting_a_reset_sends_the_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_sent_message_carries_the_correct_from_and_reply_to_headers(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        $email = $this->lastSentEmail();

        $from = $email->getFrom();
        $this->assertCount(1, $from);
        $this->assertSame('no-reply@provatferi.org', $from[0]->getAddress());
        $this->assertSame('Provatferi Literary and Cultural Center', $from[0]->getName());

        $replyTo = array_map(fn ($a) => $a->getAddress(), $email->getReplyTo());
        $this->assertSame(['support@provatferi.org'], $replyTo);

        $to = array_map(fn ($a) => $a->getAddress(), $email->getTo());
        $this->assertSame(['member@example.test'], $to);
    }

    public function test_the_message_body_contains_a_usable_reset_url(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->post('/forgot-password', ['email' => $user->email]);

        $body = (string) $this->lastSentEmail()->getHtmlBody();
        $url = $this->extractResetUrl($body);

        $this->assertNotNull($url, 'The reset mail must contain a reset URL.');
        $this->assertStringStartsWith(config('app.url').'/reset-password/', $url);

        // The URL must actually render the reset form, not 404 or redirect away.
        $this->get($url)->assertOk();
    }

    // --- Branding ----------------------------------------------------------

    /**
     * Regression guard for the branded email redesign: catches an app.name
     * config change, or a future edit to message.blade.php, silently
     * reintroducing "Provatferi ERP" (the internal admin app's own name)
     * as the visible brand in outbound mail.
     */
    public function test_the_message_never_shows_the_internal_app_name_as_its_brand(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->post('/forgot-password', ['email' => $user->email]);

        $body = (string) $this->lastSentEmail()->getHtmlBody();

        $this->assertStringNotContainsString(
            config('app.name'),
            $body,
            'The internal app name ("Provatferi ERP") leaked into the email — branding should read '.config('mail.from.name').' instead.',
        );
    }

    public function test_the_message_carries_the_public_org_name_and_official_logo(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->post('/forgot-password', ['email' => $user->email]);

        $body = (string) $this->lastSentEmail()->getHtmlBody();

        $this->assertStringContainsString(config('mail.from.name'), $body);
        $this->assertStringContainsString('brand/provatferi-logo-light.png', $body);
        $this->assertStringContainsString(config('mail.reply_to.support'), $body, 'The footer should surface a real support contact.');
    }

    public function test_the_reset_link_expiry_is_stated_in_minutes(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->post('/forgot-password', ['email' => $user->email]);

        $expiry = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        $body = (string) $this->lastSentEmail()->getHtmlBody();

        $this->assertStringContainsString((string) $expiry, $body);
    }

    public function test_the_subject_line_is_specific_and_professional(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->post('/forgot-password', ['email' => $user->email]);

        $subject = $this->lastSentEmail()->getSubject();

        $this->assertNotSame('Reset Password Notification', $subject, 'Should read as this app\'s own voice, not the framework default string.');
        $this->assertStringContainsString('password', strtolower((string) $subject));
    }

    // --- Token lifecycle -------------------------------------------------

    public function test_a_reset_token_changes_the_password_and_swaps_which_one_authenticates(): void
    {
        $user = $this->userWithOldPassword();
        $token = $this->requestResetToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $fresh->password));
    }

    public function test_the_old_password_no_longer_logs_in_after_a_reset(): void
    {
        $user = $this->userWithOldPassword();
        $this->completeReset($user);

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::OLD_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_new_password_logs_in_after_a_reset(): void
    {
        $user = $this->userWithOldPassword();
        $this->completeReset($user);

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_reset_token_cannot_be_used_twice(): void
    {
        $user = $this->userWithOldPassword();
        $token = $this->requestResetToken($user);

        // First use succeeds.
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        // Replaying the same token must be rejected outright.
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-third-password-entirely',
            'password_confirmation' => 'a-third-password-entirely',
        ])->assertSessionHasErrors('email');

        // And the replay must not have changed anything.
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    // --- Helpers ----------------------------------------------------------

    private function userWithOldPassword(): User
    {
        return User::factory()->create([
            'email' => 'member@example.test',
            'password' => Hash::make(self::OLD_PASSWORD),
        ]);
    }

    /** Runs the real forgot-password flow and returns the token that was mailed. */
    private function requestResetToken(User $user): string
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->assertNotNull($token);

        return $token;
    }

    private function completeReset(User $user): void
    {
        $token = $this->requestResetToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->flushSession();
    }

    /**
     * Regression guard for the 2026-09-09 production outage.
     *
     * AppServiceProvider shipped a ->replyTo(config('mail.reply_to.support'))
     * call while the config/mail.php defining that key did not, so on the
     * server it resolved to null. Passing null to ->replyTo() builds a
     * MailMessage without complaint and only explodes later, inside Symfony's
     * Address constructor, when the transport turns it into a real message —
     * so every build-time assertion passed while POST /forgot-password
     * returned a 500.
     *
     * This has to drive an actual send for that reason: asserting on the
     * MailMessage alone reproduces nothing.
     */
    public function test_a_missing_support_address_does_not_break_sending_a_reset(): void
    {
        config(['mail.reply_to.support' => null]);

        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        $email = $this->lastSentEmail();

        $this->assertSame([], $email->getReplyTo(), 'A null support address must be omitted, not sent as an empty header.');
        $this->assertNotEmpty($email->getTo(), 'The reset must still reach the member.');
    }

    public function test_the_reset_carries_the_support_reply_to_when_configured(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        $replyTo = $this->lastSentEmail()->getReplyTo();

        $this->assertCount(1, $replyTo);
        $this->assertSame(config('mail.reply_to.support'), $replyTo[0]->getAddress());
    }

    public function test_the_support_reply_to_config_key_exists(): void
    {
        // The outage was a config key that existed locally and not on the
        // server. Assert the key itself, so a config file that ships without
        // it fails here rather than in production.
        $this->assertIsString(
            config('mail.reply_to.support'),
            'mail.reply_to.support must be defined — AppServiceProvider reads it when branding the reset mail.',
        );
    }

    private function lastSentEmail(): Email
    {
        $messages = Mail::mailer()->getSymfonyTransport()->messages();

        $this->assertNotEmpty($messages, 'No message reached the mail transport.');

        return $messages->last()->getOriginalMessage();
    }

    private function extractResetUrl(string $body): ?string
    {
        $decoded = html_entity_decode($body, ENT_QUOTES | ENT_HTML5);

        return preg_match('#https?://[^\s"\'<]*/reset-password/[^\s"\'<]+#', $decoded, $m)
            ? rtrim($m[0], '.')
            : null;
    }
}
