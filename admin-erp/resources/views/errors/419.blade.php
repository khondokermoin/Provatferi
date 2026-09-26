@extends('errors.layout')

@section('title', __('admin.errors.419.title'))
@section('code', __('admin.errors.419.code'))
@section('heading', __('admin.errors.419.heading'))
@section('message', __('admin.errors.419.body'))
@section('actions')
    <button type="button" class="primary" onclick="history.back()">{{ __('admin.errors.back_previous_alt') }}</button>
    <a href="{{ url('/login') }}">{{ __('admin.auth.login_title') }}</a>
@endsection
