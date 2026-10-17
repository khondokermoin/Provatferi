<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\SessionTerminator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        // Every other device signed in with the old password is signed out (docs/MFA_SECURITY.md, "Sessions"); this one
        // stays. Two-step verification is untouched.
        SessionTerminator::endSessionsOf($request->user(), $request->session()->getId());

        return back()->with('success', 'পাসওয়ার্ড সফলভাবে পরিবর্তন হয়েছে।');
    }
}
