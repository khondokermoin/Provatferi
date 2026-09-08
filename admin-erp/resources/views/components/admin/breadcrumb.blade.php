@props(['items' => []])

{{-- $items: [['label' => 'Organization', 'route' => 'admin...'], ['label' => 'Current']] --}}
<nav aria-label="ব্রেডক্রাম্ব">
    <ol class="breadcrumb m-0 py-0 fs-13">
        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Provatferi</a></li>
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
