@props([
    'headers' => [],
    'paginator' => null,
    'caption' => null,
])

<div class="card">
    @isset($toolbar)
        <div class="card-header">
            {{ $toolbar }}
        </div>
    @endisset

    {{-- pf-table-stack turns rows into stacked cards under 768px (see
         provatferi-admin.css); table-responsive is the desktop-width guard. --}}
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 pf-table-stack">
            @if ($caption)
                <caption class="visually-hidden">{{ $caption }}</caption>
            @endif
            <thead class="table-light">
                <tr>
                    @foreach ($headers as $header)
                        <th scope="col" @if (($header['align'] ?? null) === 'end') class="text-end" @endif>
                            {{ is_array($header) ? $header['label'] : $header }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                {{ $slot }}
            </tbody>
        </table>
    </div>

    @if ($paginator)
        <div class="card-footer bg-transparent">
            <x-admin.pagination :paginator="$paginator" />
        </div>
    @endif
</div>
