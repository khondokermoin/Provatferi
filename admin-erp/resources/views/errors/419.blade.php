@extends('errors.layout')

@section('title', 'সেশন মেয়াদোত্তীর্ণ')
@section('code', '৪১৯')
@section('heading', 'পাতার মেয়াদ শেষ হয়ে গেছে')
@section('message', 'নিরাপত্তার জন্য দীর্ঘক্ষণ ফর্মটি খোলা থাকলে তা মেয়াদোত্তীর্ণ হয়ে যায়। পাতাটি আবার লোড করে আরেকবার চেষ্টা করুন — আগের কোনো তথ্য হারায়নি।')
@section('actions')
    <button type="button" class="primary" onclick="history.back()">পূর্বের পাতায় ফিরে যান</button>
    <a href="{{ url('/login') }}">লগইন</a>
@endsection
