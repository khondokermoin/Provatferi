@extends('errors.layout')

@section('title', __('admin.errors.404.title'))
@section('code', __('admin.errors.404.code'))
@section('heading', __('admin.errors.404.heading'))
@section('message', __('admin.errors.404.body'))
@section('actions')
    <a href="{{ url('/dashboard') }}" class="primary">{{ __('admin.errors.back_dashboard') }}</a>
    <button type="button" onclick="history.back()">{{ __('admin.errors.back_previous') }}</button>
@endsection
