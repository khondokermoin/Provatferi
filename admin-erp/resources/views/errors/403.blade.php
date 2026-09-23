@extends('errors.layout')

@section('title', 'অনুমতি নেই')
@section('code', '৪০৩')
@section('heading', 'এই পাতায় প্রবেশের অনুমতি নেই')
@section('message', 'আপনার অ্যাকাউন্টে এই পাতা দেখার প্রয়োজনীয় অনুমতি নেই। প্রয়োজনে প্রশাসকের সঙ্গে যোগাযোগ করুন।')
@section('actions')
    <a href="{{ url('/dashboard') }}" class="primary">ড্যাশবোর্ডে ফিরে যান</a>
    <button type="button" onclick="history.back()">আগের পাতায় যান</button>
@endsection
