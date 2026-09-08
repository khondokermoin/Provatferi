@props(['title', 'breadcrumbs' => []])

<div class="page-title-head d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="flex-grow-1">
        <h1 class="fs-17 mb-1">{{ $title }}</h1>
        <x-admin.breadcrumb :items="$breadcrumbs" />
    </div>

    @if (trim($slot) !== '')
        <div class="d-flex flex-wrap gap-2">{{ $slot }}</div>
    @endif
</div>
