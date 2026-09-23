@extends('errors.layout')

@section('title', 'অনুরোধ বেশি হয়ে গেছে')
@section('code', '৪২৯')
@section('heading', 'অল্প সময়ে অনেকবার চেষ্টা হয়েছে')
@section('message', 'নিরাপত্তার জন্য একটি সীমা রয়েছে। কিছুক্ষণ অপেক্ষা করে আবার চেষ্টা করুন।')
@section('actions')
    <a href="{{ url('/') }}" class="primary">হোমে ফিরে যান</a>
@endsection
