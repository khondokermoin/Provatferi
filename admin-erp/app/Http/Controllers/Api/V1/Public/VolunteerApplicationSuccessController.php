<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use App\Models\JobPostingSlug;
use App\Support\Recruitment\ApplicationSuccessProof;
use App\Support\Recruitment\CentralMessengerGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/public/recruitment/{slug}/application-success — "has this browser just submitted an application to this
 * posting?" (docs/VOLUNTEER_MESSENGER_GROUP.md). Called by the public site's SERVER when it renders the confirmation page,
 * with the proof from the visitor's HttpOnly cookie in the BODY (a URL would end up in logs and caches). The answer is the
 * only place the central Messenger group invite ever leaves the ERP:
 *
 *   {"data": {"verified": false, "messenger": null}}                      no proof, a bad one, an expired one, another posting's
 *   {"data": {"verified": true,  "messenger": null}}                      a real proof; the feature is off or has no valid invite
 *   {"data": {"verified": true,  "messenger": {"url": "https://m.me/j/<token>/"}}}
 *
 * It is always 200 and never says WHY a proof was refused (so it is no oracle), carries nothing about the application, and
 * is not cacheable. The invite is read from the settings now, not from the application, so changing or switching off the
 * link takes effect on the next load of any confirmation page.
 */
class VolunteerApplicationSuccessController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $posting = $this->posting($slug);
        // The BODY only (input() would also read the query string): a proof in an address ends up in access logs and
        // browser history, so an address is never listened to.
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $proof = $body['proof'] ?? null;
        $verified = $posting !== null && is_string($proof) && ApplicationSuccessProof::verify($proof, $posting) !== null;

        return response()
            ->json(['data' => ['verified' => $verified, 'messenger' => $verified ? CentralMessengerGroup::publicAction() : null]])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * The posting for the address, whatever its status (a posting that closed after someone applied still has a
     * confirmation to show): its current slug, or one it used to have, like the public posting endpoint.
     */
    private function posting(string $slug): ?JobPosting
    {
        $posting = JobPosting::query()->where('slug', $slug)->first();
        if ($posting !== null) {
            return $posting;
        }

        $historical = JobPostingSlug::query()->where('slug', $slug)->first();

        return $historical === null ? null : JobPosting::query()->find($historical->job_posting_id);
    }
}
