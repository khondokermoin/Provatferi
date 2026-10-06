@props(['paginator'])

@if ($paginator->hasPages())
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3" data-testid="pagination">
        <p class="text-muted fs-13 mb-0">
            {{ __('admin.pagination.showing', ['from' => bn_number($paginator->firstItem()), 'to' => bn_number($paginator->lastItem()), 'total' => bn_number($paginator->total())]) }}
        </p>
        {{ $paginator->onEachSide(1)->links('admin.partials.pagination') }}
    </div>
@elseif ($paginator->total() > 0)
    <p class="text-muted fs-13 mb-0 mt-3">{{ __('admin.pagination.total_only', ['total' => bn_number($paginator->total())]) }}</p>
@endif
