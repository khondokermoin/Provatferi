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
     * so point it at the support mailbox. Also replaces the default
     * generic copy/greeting/salutation with Provatferi's own voice — visual
     * branding (logo, colors, card layout) lives in
     * resources/views/vendor/mail/, published via `vendor:publish
     * --tag=laravel-mail` rather than edited in vendor/, so it applies to
     * every MailMessage-based notification, not just this one.
     */
    protected function bootPasswordResetMail(): void
    {
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            $expiry = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
            $firstName = trim(explode(' ', (string) ($notifiable->name ?? ''))[0] ?? '');
            $orgName = config('mail.from.name');

            $message = (new MailMessage)
                ->subject(Lang::get('Reset your Provatferi ERP password'));

            /*
             * Only set Reply-To when there is an address to set it to.
             * ->replyTo(null) does not fail here — it fails later, at send
             * time, deep inside Symfony's Address constructor, which takes
             * the whole password-reset flow down with a 500 and no usable
             * hint as to why. That is exactly what happened in production on
             * 2026-09-09: this file shipped while the config/mail.php that
             * defines mail.reply_to did not, so the key resolved to null on
             * the server while every local test passed.
             *
             * A missing contact address should cost us the Reply-To header,
             * not the ability to reset a password, so it degrades instead of
             * throwing. config/mail.php still supplies a real default; this
             * is the backstop for when config and code drift apart again.
             */
            $supportAddress = config('mail.reply_to.support');
            if (is_string($supportAddress) && $supportAddress !== '') {
                $message->replyTo($supportAddress, 'Provatferi Support');
            }

            return $message
                ->greeting($firstName !== '' ? Lang::get('Hello, :name.', ['name' => $firstName]) : Lang::get('Hello.'))
                ->line(Lang::get('We received a request to reset your account password. Use the button below to choose a new one.'))
                ->action(Lang::get('Reset Password'), $url)
                ->line(Lang::get('This link will expire in :count minutes for your security.', ['count' => $expiry]))
                ->line(Lang::get("If you didn't request this, no action is needed — your password will stay the same."))
                ->salutation(Lang::get('Regards,')."  \n".$orgName);
        });
    }
}
