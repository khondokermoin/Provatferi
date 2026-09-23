@extends('layouts.print')

@section('content')
    @include('admin.recruitment.applications.document', [
        'application' => $application,
        'contactLabels' => $contactLabels,
        'photoSrc' => $photoSrc,
        'logoSrc' => $logoSrc,
        'generatedAt' => $generatedAt,
    ])
@endsection
