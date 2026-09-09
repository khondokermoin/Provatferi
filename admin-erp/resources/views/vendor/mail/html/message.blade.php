<x-mail::layout>
{{-- Header --}}
<x-slot:header>
{{--
    header.blade.php no longer uses this slot's text content — it renders
    the Provatferi wordmark unconditionally — so nothing branded needs to
    pass through here.
--}}
<x-mail::header :url="config('app.url')" />
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{--
    "mail.from.name" (Provatferi Literary and Cultural Center), not
    "app.name" (Provatferi ERP) — this is the organisation's public identity
    for anything it sends, decoupled from the internal admin app's own name.
    Automated-mail disclosure line ties back to why the message arrived at
    all, matching the intro line in AppServiceProvider::bootPasswordResetMail().
--}}
**{{ config('mail.from.name') }}**

This is an automated message from Provatferi's official systems. For help, contact [{{ config('mail.reply_to.support') }}](mailto:{{ config('mail.reply_to.support') }}).

© {{ date('Y') }} {{ config('mail.from.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
