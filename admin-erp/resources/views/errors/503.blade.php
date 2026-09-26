@extends('errors.layout')

@section('title', __('admin.errors.503.title'))
@section('code', __('admin.errors.503.code'))
@section('heading', __('admin.errors.503.heading'))
@section('message', __('admin.errors.503.body'))
@section('actions')
    <a href="{{ url('/') }}" class="primary">{{ __('admin.errors.retry') }}</a>
@endsection
