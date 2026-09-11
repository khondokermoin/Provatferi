@props(['items' => []])

{{-- $items: [['label' => 'Organization', 'route' => 'admin...'], ['label' => 'Current']] --}}
<nav aria-label="ব্রেডক্রাম্ব">
    <ol class="breadcrumb m-0 py-0 fs-13">
        {{-- PROFILE-005: was the hardcoded English "Provatferi"; every other
             occurrence of the name in the panel's chrome (header, sidebar,
             footer) is the Bengali wordmark, so the breadcrumb root now
             matches instead of being the one English word in the trail. --}}
        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">প্রভাতফেরী</a></li>
        @foreach ($items as $item)
            @if (! $loop->last && ! empty($item['route']))
                <li class="breadcrumb-item">
                    <a href="{{ route($item['route'], $item['params'] ?? []) }}">{{ $item['label'] }}</a>
                </li>
            @else
                <li class="breadcrumb-item active" aria-current="page">{{ $item['label'] }}</li>
            @endif
        @endforeach
    </ol>
</nav>
