{{-- The admin panel's page links (used by components/admin/pagination.blade.php). Laravel's default view is Tailwind
     markup, which this Bootstrap panel has no styles for: until 2026-10-06 every paginated list showed oversized bare
     chevrons and an English "Showing … results" line, even in Bangla. Bootstrap's own .pagination, the panel's icon
     subset, page numbers in the admin's digits, and labels from the panel's own language files. --}}
@if ($paginator->hasPages())
    <nav aria-label="{{ __('admin.pagination.label') }}">
        <ul class="pagination pagination-sm flex-wrap mb-0">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link"><i class="ti ti-arrow-left" aria-hidden="true"></i><span class="visually-hidden">{{ __('admin.pagination.previous') }}</span></span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev"><i class="ti ti-arrow-left" aria-hidden="true"></i><span class="visually-hidden">{{ __('admin.pagination.previous') }}</span></a>
                </li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="page-item disabled" aria-disabled="true"><span class="page-link">…</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="page-item active" aria-current="page"><span class="page-link">{{ bn_number($page) }}</span></li>
                        @else
                            <li class="page-item"><a class="page-link" href="{{ $url }}">{{ bn_number($page) }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next"><i class="ti ti-arrow-right" aria-hidden="true"></i><span class="visually-hidden">{{ __('admin.pagination.next') }}</span></a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link"><i class="ti ti-arrow-right" aria-hidden="true"></i><span class="visually-hidden">{{ __('admin.pagination.next') }}</span></span>
                </li>
            @endif
        </ul>
    </nav>
@endif
