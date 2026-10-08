<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Committee;
use App\Models\CommitteeRegistrationLink;
use App\Support\AdminTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        $data = $request->validate(['expires_at' => ['nullable', 'date']]);
        // Typed in Bangladesh time (the form says so) and stored as the UTC instant the link is checked against.
        // Until 2026-10-08 the typed value was stored as if it were UTC: a link set to expire at 18:00 lasted until
        // midnight in Dhaka.
        $expiresAt = AdminTime::fromInput($data['expires_at'] ?? null);
        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw ValidationException::withMessages(['expires_at' => __('admin.fields.expiry_must_be_future')]);
        }

        [, $raw] = CommitteeRegistrationLink::issue($committee, $expiresAt, $request->user());

        $url = rtrim(config('services.public_site.url'), '/').'/committee/register/'.$raw;

        return back()->with('success', __('admin.flash.registration_link_created'))
            ->with('generated_registration_link', $url);
    }

    public function revoke(Committee $committee, CommitteeRegistrationLink $link): RedirectResponse
    {
        abort_unless($link->committee_id === $committee->id, 404);

        $link->revoke();

        return back()->with('success', __('admin.flash.registration_link_revoked'));
    }
}
