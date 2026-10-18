@extends('errors.layout')

@section('title', __('admin.errors.422.title'))
@section('code', __('admin.errors.422.code'))
@section('heading', __('admin.errors.422.heading'))
@section('message', __('admin.errors.422.body'))
@section('actions')
    <button type="button" class="primary" onclick="history.back()">{{ __('admin.errors.back_previous_alt') }}</button>
    <a href="{{ url('/dashboard') }}">{{ __('admin.errors.back_dashboard') }}</a>
@endsection
