<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Mirrors EnsurePermission middleware, which stays authoritative for
        // access control. These gates only let Blade hide actions a user can't
        // perform, so the UI never offers a button that would 403.
        Gate::before(fn (User $user) => $user->hasRole('super_admin') ? true : null);

        foreach (Permission::MODULES as $module) {
            foreach (Permission::ACTIONS as $action) {
                $ability = "{$module}.{$action}";
                Gate::define($ability, fn (User $user) => $user->hasPermission($ability));
            }
        }

        $this->bootPasswordResetMail();
    }

    /**
     * The framework's password-reset mail carries no Reply-To, so it would go
     * out from the no-reply mailbox with no way for a member to answer it.
     * A reset is exactly the message someone replies to when they are stuck,
     * so point it at the support mailbox.
     */
    protected function bootPasswordResetMail(): void
    {
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            $expiry = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject(Lang::get('Reset Password Notification'))
                ->replyTo(config('mail.reply_to.support'))
                ->line(Lang::get('You are receiving this email because we received a password reset request for your account.'))
                ->action(Lang::get('Reset Password'), $url)
                ->line(Lang::get('This password reset link will expire in :count minutes.', ['count' => $expiry]))
                ->line(Lang::get('If you did not request a password reset, no further action is required.'));
        });
    }
}
