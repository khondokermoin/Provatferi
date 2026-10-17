{{--
    What a visitor reads on ONE language of the website: the card on the Leadership page and the profile page itself.
    $profile, $lang ('bn' | 'en').

    The labels inside this block are the website's own words in the page's language, not the admin's language, because this
    is a picture of the public page. The rule is the site's rule (lib/i18n/pick.ts): the Bangla text is the base; on the
    English page a field without an English text shows the Bangla text with a translation note — never an invented translation.
--}}
@php
    $en = $lang === 'en';
    // The page's own words, looked up in the page's language (an explicit locale), not the admin's.
    $word = fn (string $key, array $replace = []): string => __('admin.leadership.page_words.'.$key, $replace, $lang);
    $words = [
        'note' => $word('note'),
        'since' => fn ($year) => $word('since', ['year' => $en ? $year : bn_digits((string) $year)]),
        'expertise' => $word('expertise'),
        'contact' => $word('contact'),
        'website' => $word('website'),
        'email' => $word('email'),
        'phone' => $word('phone'),
    ];

    /** @return array{0: string, 1: bool} the text for this page's language, and whether it is the Bangla fallback */
    $pick = function (?string $bn, ?string $other) use ($en): array {
        if (! $en) {
            return [(string) $bn, false];
        }

        return filled($other) ? [(string) $other, false] : [(string) $bn, true];
    };

    [$name, $nameFallback] = $pick($profile->name_bn, $profile->name_en);
    [$designation, $designationFallback] = $pick($profile->designation_bn, $profile->designation_en);
    [$shortBio, $shortFallback] = $pick($profile->short_bio_bn, $profile->short_bio_en);
    [$fullBio, $fullFallback] = $pick($profile->full_bio_bn, $profile->full_bio_en);
    [$quote, $quoteFallback] = $pick($profile->quote_bn, $profile->quote_en);
    [$expertise, $expertiseFallback] = $pick($profile->expertise_bn, $profile->expertise_en);

    $initials = collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $paragraphs = fn (string $text): array => array_values(array_filter(array_map('trim', preg_split('/\n{2,}/', str_replace("\r\n", "\n", $text)) ?: [])));
    $links = collect([
        ['Facebook', $profile->facebook_url], ['LinkedIn', $profile->linkedin_url], ['YouTube', $profile->youtube_url], [$words['website'], $profile->website_url],
    ])->filter(fn ($link) => filled($link[1]));
@endphp

<div lang="{{ $lang }}">
    <h3 class="fs-14 text-muted mb-2">{{ __('admin.leadership.preview_card') }}</h3>
    <div class="card mb-4">
        <div class="card-body d-flex gap-3 align-items-start">
            @if ($profile->photoSmUrl())
                <img src="{{ $profile->photoSmUrl() }}" alt="{{ $name }}" class="rounded border" style="width: 112px; height: 140px; object-fit: cover; object-position: center 20%;">
            @else
                <span class="pf-avatar pf-avatar-lg" aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
            @endif
            <div>
                <h4 class="fs-16 mb-1">{{ $name }}</h4>
                <p class="mb-2 text-muted">{{ $designation }}</p>
                @if ($shortBio !== '')
                    <p class="mb-0 fs-13">{{ $shortBio }}</p>
                @endif
                @if ($en && ($nameFallback || $designationFallback || ($shortBio !== '' && $shortFallback)))
                    <p class="mb-0 mt-2 fs-12 text-muted fst-italic">{{ $words['note'] }}</p>
                @endif
            </div>
        </div>
    </div>

    <h3 class="fs-14 text-muted mb-2">{{ __('admin.leadership.preview_page') }}</h3>
    <div class="card">
        <div class="card-body">
            <div class="d-flex gap-3 align-items-start flex-wrap mb-3">
                @if ($profile->photoUrl())
                    <img src="{{ $profile->photoUrl() }}" alt="{{ $name }}" class="rounded border" style="width: 200px; max-width: 100%; height: auto;"
                         @if ($profile->photo_width && $profile->photo_height) width="{{ $profile->photo_width }}" height="{{ $profile->photo_height }}" @endif>
                @else
                    <div>
                        <span class="pf-avatar pf-avatar-lg" aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
                        <p class="text-muted fs-12 mt-1 mb-0">{{ __('admin.leadership.preview_no_photo') }}</p>
                    </div>
                @endif
                <div>
                    <h2 class="fs-20 mb-1">{{ $name }}</h2>
                    <p class="mb-1 fs-15">{{ $designation }}</p>
                    @if ($expertise !== '')
                        <p class="mb-1 text-muted fs-13"><strong>{{ $words['expertise'] }}:</strong> {{ $expertise }}</p>
                    @endif
                    @if ($profile->associated_since_year)
                        <p class="mb-0 text-muted fs-13">{{ $words['since']($profile->associated_since_year) }}</p>
                    @endif
                </div>
            </div>

            @if ($quote !== '')
                <blockquote class="border-start border-3 ps-3 my-3 fst-italic">{{ $quote }}</blockquote>
            @endif

            @if ($fullBio !== '')
                @foreach ($paragraphs($fullBio) as $paragraph)
                    <p>{!! nl2br(e($paragraph)) !!}</p>
                @endforeach
            @elseif ($shortBio !== '')
                <p>{{ $shortBio }}</p>
            @endif

            @if ($profile->public_email || $profile->public_phone || $links->isNotEmpty())
                <h3 class="fs-14 mt-3">{{ $words['contact'] }}</h3>
                <ul class="list-unstyled mb-0 fs-13">
                    @if ($profile->public_email)<li>{{ $words['email'] }}: {{ $profile->public_email }}</li>@endif
                    @if ($profile->public_phone)<li>{{ $words['phone'] }}: {{ $profile->public_phone }}</li>@endif
                    @foreach ($links as [$linkLabel, $linkUrl])
                        <li>{{ $linkLabel }}: <span class="text-break">{{ $linkUrl }}</span></li>
                    @endforeach
                </ul>
            @endif

            @if ($en && (($fullBio !== '' && $fullFallback) || ($quote !== '' && $quoteFallback) || ($expertise !== '' && $expertiseFallback)))
                <p class="mb-0 mt-3 fs-12 text-muted fst-italic">{{ $words['note'] }}</p>
            @endif
        </div>
    </div>
</div>
