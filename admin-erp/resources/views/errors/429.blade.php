@extends('errors.layout')

@section('title', __('admin.errors.429.title'))
@section('code', __('admin.errors.429.code'))
@section('heading', __('admin.errors.429.heading'))
@section('message', __('admin.errors.429.body'))
@section('actions')
    <a href="{{ url('/') }}" class="primary">{{ __('admin.errors.back_home') }}</a>
@endsection
