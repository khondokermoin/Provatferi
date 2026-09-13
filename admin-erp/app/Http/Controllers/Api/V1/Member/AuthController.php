<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * §12: server-to-server only — the Next.js member portal calls this from a
 * Server Action and stores the returned token in ITS OWN HttpOnly cookie;
 * the browser never talks to admin.provatferi.org directly and never sees
 * this token. Completely separate from Api\V1\AuthController (ERP staff) —
 * no shared guard, no shared session, per config/auth.php's own design note.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // No 'members' guard exists to Auth::attempt() against — only a
        // provider (config/auth.php), used by the password broker. Sanctum
        // resolves tokens polymorphically instead of a session guard, so
        // login itself is a direct credential check against the model.
        $member = Member::query()->where('email', $credentials['email'])->first();

        if (! $member || ! Hash::check($credentials['password'], $member->password)) {
            throw ValidationException::withMessages(['email' => 'ই-মেইল অথবা পাসওয়ার্ড সঠিক নয়।']);
        }

        if ($member->status !== 'active') {
            throw ValidationException::withMessages(['email' => 'এই অ্যাকাউন্টটি বর্তমানে সক্রিয় নয়।']);
        }

        $member->forceFill(['last_login_at' => now()])->save();
        $token = $member->createToken('member-portal')->plainTextToken;

        return response()->json(['data' => ['token' => $token]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
