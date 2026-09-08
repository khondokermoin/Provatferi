@extends('layouts.admin')

@section('content')
    <form method="POST" action="{{ route('admin.content.vision.update') }}">
        @csrf
        @method('PUT')

        <div class="row">
            <div class="col-lg-7">
                <x-admin.card title="Vision" subtitle="দীর্ঘ-ফরম্যাট কনটেন্ট — সর্বশেষ হালনাগাদ: {{ $block->updated_at?->format('d M Y, H:i') ?? '—' }}">
                    <x-admin.form-textarea name="body" label="বিবরণ" :value="$block->body" :rows="8" required
                        :disabled="! auth()->user()->can('settings.update')" />

                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="is_public" name="is_public" value="1"
                               @checked(old('is_public', $block->is_public)) @disabled(! auth()->user()->can('settings.update'))>
                        <label class="form-check-label" for="is_public">সাইটে প্রকাশযোগ্য</label>
                    </div>
                </x-admin.card>

                @can('settings.update')
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>সংরক্ষণ করুন
                        </button>
                    </div>
                @endcan
            </div>

            <div class="col-lg-5">
                <x-admin.card title="প্রিভিউ" subtitle="পাবলিক সাইটে ধারণাগতভাবে যেভাবে দেখাবে।">
                    <span class="badge bg-success-subtle text-success-emphasis mb-2">Vision</span>
                    <p class="mb-0">{{ $block->body ?: '—' }}</p>
                    @unless ($block->is_public)
                        <div class="alert alert-warning fs-13 mb-0 mt-3">
                            <i class="ti ti-eye-off me-1" aria-hidden="true"></i>বর্তমানে অপ্রকাশিত হিসেবে চিহ্নিত।
                        </div>
                    @endunless
                </x-admin.card>
            </div>
        </div>
    </form>
@endsection
