@extends('errors.layout')

@section('title', 'পাতা পাওয়া যায়নি')
@section('code', '৪০৪')
@section('heading', 'পাতাটি খুঁজে পাওয়া যায়নি')
@section('message', 'যে লিংকে এসেছেন তা হয়তো মুছে ফেলা হয়েছে অথবা ঠিকানাটি ভুল।')
@section('actions')
    <a href="{{ url('/dashboard') }}" class="primary">ড্যাশবোর্ডে ফিরে যান</a>
    <a href="{{ url('/login') }}">লগইন</a>
@endsection
