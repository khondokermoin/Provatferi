@extends('errors.layout')

@section('title', 'রক্ষণাবেক্ষণ চলছে')
@section('code', '৫০৩')
@section('heading', 'সাময়িকভাবে বন্ধ আছে')
@section('message', 'সিস্টেমে প্রয়োজনীয় রক্ষণাবেক্ষণ কাজ চলছে। শীঘ্রই আবার চালু হবে — একটু পর আবার চেষ্টা করুন।')
@section('actions')
    <a href="{{ url('/') }}" class="primary">আবার চেষ্টা করুন</a>
@endsection
