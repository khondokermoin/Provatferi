@props(['id', 'title', 'confirmLabel' => 'নিশ্চিত করুন', 'variant' => 'danger'])

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-16" id="{{ $id }}-title">{{ $title }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="বন্ধ করুন"></button>
            </div>
            <div class="modal-body">
                {{ $slot }}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">বাতিল</button>
                @isset($confirm)
                    {{ $confirm }}
                @endisset
            </div>
        </div>
    </div>
</div>
