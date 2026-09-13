<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * §12: the only way a Member ever gains a usable password — every Member
 * row is created (MembershipController::findOrCreateMember()) with a random
 * 40-char password nobody knows, deliberately, so "set your password" and
 * "forgot your password" are the same flow. Uses the 'members' broker
 * (config/auth.php) throughout — never the default 'users' one, which would
 * look the token up against the wrong table entirely.
 */
class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']], [], ['email' => 'ই-মেইল']);

        Password::broker('members')->sendResetLink($request->only('email'));

        // Always the same response regardless of whether the address is
        // registered — an enumeration-safe "forgot password" endpoint.
        return response()->json(['message' => 'যদি এই ই-মেইলে অ্যাকাউন্ট থাকে, তাহলে একটি পাসওয়ার্ড-সেট লিংক পাঠানো হয়েছে।']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [], ['email' => 'ই-মেইল', 'password' => 'পাসওয়ার্ড']);

        $status = Password::broker('members')->reset(
            $data,
            function (Member $member) use ($data) {
                $member->forceFill(['password' => Hash::make($data['password'])])->save();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'লিংকটি অবৈধ অথবা মেয়াদোত্তীর্ণ।']);
        }

        return response()->json(['message' => 'পাসওয়ার্ড সফলভাবে সেট করা হয়েছে।']);
    }
}
