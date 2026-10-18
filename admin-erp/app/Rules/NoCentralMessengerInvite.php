<?php

namespace App\Rules;

use App\Support\Recruitment\CentralMessengerGroup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Keeps the central Messenger group invite out of text an admin writes for a PUBLIC page (a notice, a recruitment
 * posting): the invite belongs on the confirmation that follows a verified application, nowhere else
 * (docs/VOLUNTEER_MESSENGER_GROUP.md). It matches the invite's token, so every form of the link is caught, and it applies
 * whether or not the feature is switched on, because the token is the secret.
 */
final class NoCentralMessengerInvite implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && CentralMessengerGroup::containsInvite($value)) {
            $fail(__('admin.messenger.not_in_public_text'));
        }
    }
}
