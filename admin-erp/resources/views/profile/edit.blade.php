@extends('layouts.admin')

@section('content')
    <div class="row">
        <div class="col-xl-8">
            @include('profile.partials.update-profile-information-form')
            @include('profile.partials.update-password-form')
            @include('profile.partials.delete-user-form')
        </div>
    </div>
@endsection
