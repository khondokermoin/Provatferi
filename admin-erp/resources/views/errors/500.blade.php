@extends('errors.layout')

@section('title', 'সার্ভার সমস্যা')
@section('code', '৫০০')
@section('heading', 'একটি সমস্যা হয়েছে')
@section('message', 'আমাদের পক্ষ থেকে একটি ত্রুটি হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন — সমস্যা চলতে থাকলে প্রশাসকের সঙ্গে যোগাযোগ করুন।')
@section('actions')
    <a href="{{ url('/') }}" class="primary">হোমে ফিরে যান</a>
@endsection
