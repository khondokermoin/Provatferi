@props(['paginator'])

@if ($paginator->hasPages())
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
        <p class="text-muted fs-13 mb-0">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} / {{ $paginator->total() }}
        </p>
        {{ $paginator->onEachSide(1)->links() }}
    </div>
@elseif ($paginator->total() > 0)
    <p class="text-muted fs-13 mb-0 mt-3">মোট {{ $paginator->total() }}টি</p>
@endif
