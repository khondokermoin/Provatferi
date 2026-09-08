@extends('layouts.admin')

@section('content')
    @php $isEdit = $objective->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.content.objectives.update', $objective) : route('admin.content.objectives.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="উদ্দেশ্য">
                    <x-admin.form-input name="title" label="শিরোনাম (ঐচ্ছিক)" :value="$objective->title"
                        help="সাধারণত উদ্দেশ্যের কোনো আলাদা শিরোনাম দরকার হয় না — শুধু বিবরণই যথেষ্ট।" />
                    <x-admin.form-textarea name="body" label="বিবরণ" :value="$objective->body" :rows="3" required />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="প্রদর্শন">
                    <x-admin.form-input name="sort_order" label="ক্রম" type="number" :value="$objective->sort_order ?? 0" required min="0"
                        help="ছোট সংখ্যা আগে দেখাবে। তালিকা থেকেও ↑/↓ দিয়ে সাজানো যায়।" />
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="active" name="active" value="1"
                               @checked(old('active', $objective->active ?? true))>
                        <label class="form-check-label" for="active">সক্রিয় (পাবলিক তালিকায় দেখাবে)</label>
                    </div>
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? 'হালনাগাদ করুন' : 'যোগ করুন' }}
                    </button>
                    <a href="{{ route('admin.content.objectives.index') }}" class="btn btn-light">বাতিল</a>
                </div>
            </div>
        </div>
    </form>
@endsection
