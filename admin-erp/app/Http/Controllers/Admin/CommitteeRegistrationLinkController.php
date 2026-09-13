<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Committee;
use App\Models\CommitteeRegistrationLink;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * §22: issues/revokes the public link a committee nominee registers through.
 * The link itself is a Next.js page (provatferi.org/committee/register/...,
 * §5 of the public UI phase) — not a Laravel route — so its security comes
 * entirely from the model's own cryptographically random token + SHA-256
 * hash-at-rest + expiry/revocation (CommitteeRegistrationLink::issue()),
 * verified later by a public API endpoint, not from Laravel's URL signing.
 * The raw token only ever exists in the redirect flash (never persisted,
 * never logged) so the admin can copy it once — reissuing means generating a
 * new link, not recovering an old one.
 */
class CommitteeRegistrationLinkController extends Controller
{
    public function store(Request $request, Committee $committee): RedirectResponse
    {
        $data = $request->validate(['expires_at' => ['nullable', 'date', 'after:now']]);

        [, $raw] = CommitteeRegistrationLink::issue(
            $committee,
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            $request->user(),
        );

        $url = rtrim(config('services.public_site.url'), '/').'/committee/register/'.$raw;

        return back()->with('success', 'নিবন্ধন লিংক তৈরি হয়েছে — একবারই দেখানো হবে, এখনই কপি করুন।')
            ->with('generated_registration_link', $url);
    }

    public function revoke(Committee $committee, CommitteeRegistrationLink $link): RedirectResponse
    {
        abort_unless($link->committee_id === $committee->id, 404);

        $link->revoke();

        return back()->with('success', 'নিবন্ধন লিংক বাতিল করা হয়েছে।');
    }
}
