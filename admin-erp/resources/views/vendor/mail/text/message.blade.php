<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ config('mail.from.name') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer: the plain-text twin of html/message.blade.php — the organisation's public name (mail.from.name), never
         the internal app name, and the support role as the help address (docs/EMAIL_ARCHITECTURE.md). --}}
    <x-slot:footer>
        <x-mail::footer>
            @php($supportAddress = \App\Support\InstitutionMail::address('support'))
            This is an automated message from Provatferi's official systems.@if ($supportAddress) For help, contact {{ $supportAddress }}.@endif

            © {{ \App\Support\AdminTime::year() }} {{ config('mail.from.name') }}. @lang('All rights reserved.')
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
