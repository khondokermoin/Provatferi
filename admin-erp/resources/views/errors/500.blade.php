@extends('errors.layout')

@section('title', __('admin.errors.500.title'))
@section('code', __('admin.errors.500.code'))
@section('heading', __('admin.errors.500.heading'))
@section('message', __('admin.errors.500.body'))
@section('actions')
    <a href="{{ url('/') }}" class="primary">{{ __('admin.errors.back_home') }}</a>
@endsection
