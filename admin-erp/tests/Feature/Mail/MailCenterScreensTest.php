<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\Route;

/**
 * EVERY Mail Center screen must actually render (docs/MAIL_CENTER.md, "Release gates").
 *
 * WHY THIS EXISTS. On 2026-10-26 a new administration screen shipped to production and answered 500 for about an hour
 * and three quarters. It extended a layout this panel does not have and used a Blade component that has never existed.
 * 1,736 tests were green at the time: the model, the resolver, the transport routing and the authorisation were all
 * covered, and not one test ASKED FOR THE PAGE. A template that could never be produced went through the whole suite
 * and the release pipeline unnoticed.
 *
 * So this test does not name screens one by one — a new screen would simply be forgotten again. It reads the ROUTE
 * TABLE, takes every GET route the Mail Center owns, and opens each one as an account allowed to use it. A route added
 * later is covered the day it is added, or this test fails because nobody taught it how to build that route's
 * parameters.
 *
 * It is the render half of the gate. The compile half runs in the deployment pipeline: `smoke-test-isolated` compiles
 * every Blade template of the release, so a missing component fails before any switch is armed. Compilation cannot
 * catch a missing @extends parent (that resolves at render time) and rendering cannot run in the pipeline, so both
 * halves are needed.
 */
class MailCenterScreensTest extends MailCenterTestCase
{
    /** Routes that are not screens: they answer JSON, a file, or act and redirect. */
    private const NOT_A_SCREEN = ['admin.mail.unread'];

    /**
     * The Mail Center's front door sends the reader to the first mailbox they may use, and only renders a page of its
     * own when they have none. Both answers are correct, so both are accepted — the point of this test is that a
     * screen RENDERS when it is asked to, not that every route returns 200.
     */
    private const MAY_REDIRECT = ['admin.mail.index'];

    /**
     * Every GET route under the Mail Center, with the parameters needed to open it.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    private function screens(): array
    {
        $box = $this->boxes['partnerships'];
        $identity = $this->ownIdentity($box);

        return [
            'admin.mail.index' => ['admin.mail.index', []],
            'admin.mail.mailbox' => ['admin.mail.mailbox', ['mailbox' => $box->getKey()]],
            'admin.mail.message' => ['admin.mail.message', ['mailbox' => $box->getKey(), 'uid' => 1]],
            'admin.mail.compose' => ['admin.mail.compose', ['mailbox' => $box->getKey()]],
            'admin.mail.reply' => ['admin.mail.reply', ['mailbox' => $box->getKey(), 'uid' => 1]],
            'admin.mail.forward' => ['admin.mail.forward', ['mailbox' => $box->getKey(), 'uid' => 1]],
            'admin.mail.mailboxes.index' => ['admin.mail.mailboxes.index', []],
            'admin.mail.mailboxes.create' => ['admin.mail.mailboxes.create', []],
            'admin.mail.mailboxes.edit' => ['admin.mail.mailboxes.edit', ['mailbox' => $box->getKey()]],
            'admin.mail.identities.create' => ['admin.mail.identities.create', ['mailbox' => $box->getKey()]],
            'admin.mail.identities.edit' => ['admin.mail.identities.edit', ['mailbox' => $box->getKey(), 'identity' => $identity->getKey()]],
            'admin.mail.audit' => ['admin.mail.audit', []],
            'admin.mail.attachment' => ['admin.mail.attachment', ['mailbox' => $box->getKey(), 'uid' => 1, 'index' => 0]],
        ];
    }

    public function test_every_mail_center_get_route_is_covered_by_this_test(): void
    {
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! is_string($name) || ! str_starts_with($name, 'admin.mail.') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (in_array($name, self::NOT_A_SCREEN, true)) {
                continue;
            }
            $registered[] = $name;
        }
        sort($registered);
        $covered = array_keys($this->screens());
        sort($covered);

        $this->assertSame($registered, $covered,
            'A Mail Center GET route is not opened by this test. Add it to screens() with the parameters it needs — a '
            .'screen nobody asks for is how a template that cannot render reaches production.');
    }

    public function test_every_mail_center_screen_renders_for_an_account_allowed_to_open_it(): void
    {
        // The Super Admin reaches every one of them, which is the point: one account, every screen, really rendered.
        // Enrolled in two-step verification, because the administration screens require a recent code (mfa.recent).
        $owner = $this->superAdmin();
        $this->enrol($owner);
        $owner = $owner->fresh();
        $this->fake->addMessage($this->boxes['partnerships']->address, 'INBOX', 1, ['subject' => 'A message'], 'Body', '', [
            ['content' => 'a note', 'filename' => 'note.txt', 'contentType' => 'text/plain'],
        ]);

        $failures = [];
        foreach ($this->screens() as $label => [$name, $parameters]) {
            try {
                $response = $this->asVerified($owner)->get(route($name, $parameters));
                $status = $response->getStatusCode();
                $allowed = in_array($label, self::MAY_REDIRECT, true) ? [200, 302] : [200];
                if (! in_array($status, $allowed, true)) {
                    $failures[] = "{$label}: HTTP {$status}";
                }
            } catch (\Throwable $e) {
                // A missing layout or component surfaces here, which is exactly what went unnoticed before.
                $failures[] = "{$label}: ".class_basename($e).' — '.mb_substr($e->getMessage(), 0, 160);
            }
        }

        $this->assertSame([], $failures, "Mail Center screens that did not render:\n".implode("\n", $failures));
    }

    public function test_the_identity_screens_render_in_both_admin_languages(): void
    {
        $owner = $this->superAdmin();
        $this->enrol($owner);
        $box = $this->boxes['partnerships'];

        foreach (['en', 'bn'] as $locale) {
            $owner->fresh()->forceFill(['ui_locale' => $locale])->save();
            $this->asVerified($owner->fresh())->get(route('admin.mail.identities.create', $box))
                ->assertOk()
                ->assertSee(trans('admin.mail.identities.provider_note', [], $locale));
        }
    }

    public function test_a_screen_that_cannot_render_is_a_test_failure_not_a_silent_pass(): void
    {
        // Guards the guard: if rendering stopped being exercised, this would pass while the suite above quietly
        // stopped proving anything. A view that does not exist must raise, and the loop above must notice.
        $this->expectException(\InvalidArgumentException::class);
        view('admin.mail.a-view-that-does-not-exist')->render();
    }
}
