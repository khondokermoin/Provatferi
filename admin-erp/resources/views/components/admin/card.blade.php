@props(['title' => null, 'subtitle' => null, 'bodyClass' => ''])

<div {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($actions))
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <div class="flex-grow-1">
                @if ($title)
                    <h2 class="card-title mb-0 fs-15">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="text-muted fs-13 mb-0 mt-1">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="d-flex flex-wrap gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div class="card-body {{ $bodyClass }}">
        {{ $slot }}
    </div>
</div>
