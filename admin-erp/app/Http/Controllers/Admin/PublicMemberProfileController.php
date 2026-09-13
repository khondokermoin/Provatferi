<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalHistory;
use App\Models\Membership;
use App\Models\PublicMemberProfileVersion;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * §14: admin review of a member's own profile-content submissions. Scoped
 * through the Membership-keyed member show page (admin.membership.members.*)
 * for URL consistency with the rest of that screen, but every action here
 * operates on the underlying Member — a public profile belongs to the
 * portal identity, not to any one Membership record.
 */
class PublicMemberProfileController extends Controller
{
    public function approve(Request $request, Membership $membership, PublicMemberProfileVersion $version): RedirectResponse
    {
        $this->assertBelongsToMembership($membership, $version);

        $version->approveAndPublish($request->user());
        $membership->member->forceFill(['public_profile_approved' => true])->save();

        ApprovalHistory::record($version, 'approved', $request->user());

        return back()->with('success', 'পাবলিক প্রোফাইল অনুমোদিত ও প্রকাশিত হয়েছে।');
    }

    public function reject(Request $request, Membership $membership, PublicMemberProfileVersion $version): RedirectResponse
    {
        $this->assertBelongsToMembership($membership, $version);

        $data = $request->validate(['note' => ['required', 'string', 'max:1000']], [], ['note' => 'কারণ']);

        $version->forceFill([
            'status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ])->save();

        ApprovalHistory::record($version, 'rejected', $request->user(), $data['note']);

        return back()->with('success', 'পাবলিক প্রোফাইল প্রত্যাখ্যান করা হয়েছে।');
    }

    private function assertBelongsToMembership(Membership $membership, PublicMemberProfileVersion $version): void
    {
        abort_unless($membership->member_id && $version->member_id === $membership->member_id, 404);
        abort_unless($version->status === 'pending', 422, 'এই সংস্করণটি ইতিমধ্যে পর্যালোচনা করা হয়েছে।');
    }
}
