@extends('errors.layout')

@section('title', 'পাতা পাওয়া যায়নি')
@section('code', '৪০৪')
@section('heading', 'পাতাটি খুঁজে পাওয়া যায়নি')
@section('message', 'আপনি যে পাতাটি খুঁজছেন সেটি সরানো হয়েছে অথবা ঠিকানাটি সঠিক নয়।')
@section('actions')
    <a href="{{ url('/dashboard') }}" class="primary">ড্যাশবোর্ডে ফিরে যান</a>
    <button type="button" onclick="history.back()">আগের পাতায় যান</button>
@endsection
