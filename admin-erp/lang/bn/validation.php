<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines (SYSTEM-007)
    |--------------------------------------------------------------------------
    |
    | Mirrors vendor/laravel/framework/.../lang/en/validation.php key-for-key,
    | so any Validator call anywhere in the app — not just auth/profile —
    | renders in Bengali instead of silently falling back to the framework's
    | bundled English the moment a rule isn't covered by a form's own custom
    | ->messages(). :attribute/:other/:value/etc. placeholders are Laravel's
    | own substitution tokens and are kept verbatim, not translated.
    |
    */

    'accepted' => ':attribute অবশ্যই গ্রহণ করতে হবে।',
    'accepted_if' => ':other :value হলে :attribute অবশ্যই গ্রহণ করতে হবে।',
    'active_url' => ':attribute অবশ্যই একটি সঠিক URL হতে হবে।',
    'after' => ':attribute অবশ্যই :date এর পরের একটি তারিখ হতে হবে।',
    'after_or_equal' => ':attribute অবশ্যই :date অথবা তার পরের একটি তারিখ হতে হবে।',
    'alpha' => ':attribute শুধুমাত্র অক্ষর দিয়ে গঠিত হতে হবে।',
    'alpha_dash' => ':attribute শুধুমাত্র অক্ষর, সংখ্যা, ড্যাশ ও আন্ডারস্কোর দিয়ে গঠিত হতে হবে।',
    'alpha_num' => ':attribute শুধুমাত্র অক্ষর ও সংখ্যা দিয়ে গঠিত হতে হবে।',
    'any_of' => ':attribute সঠিক নয়।',
    'array' => ':attribute অবশ্যই একটি তালিকা (array) হতে হবে।',
    'ascii' => ':attribute-এ শুধুমাত্র সিঙ্গেল-বাইট অক্ষর ও প্রতীক থাকতে পারে।',
    'before' => ':attribute অবশ্যই :date এর আগের একটি তারিখ হতে হবে।',
    'before_or_equal' => ':attribute অবশ্যই :date অথবা তার আগের একটি তারিখ হতে হবে।',
    'between' => [
        'array' => ':attribute-এ অবশ্যই :min থেকে :max টি আইটেম থাকতে হবে।',
        'file' => ':attribute অবশ্যই :min থেকে :max কিলোবাইটের মধ্যে হতে হবে।',
        'numeric' => ':attribute অবশ্যই :min থেকে :max এর মধ্যে হতে হবে।',
        'string' => ':attribute অবশ্যই :min থেকে :max অক্ষরের মধ্যে হতে হবে।',
    ],
    'boolean' => ':attribute অবশ্যই সত্য অথবা মিথ্যা (true/false) হতে হবে।',
    'can' => ':attribute-এ একটি অননুমোদিত মান রয়েছে।',
    'confirmed' => ':attribute নিশ্চিতকরণ মিলছে না।',
    'contains' => ':attribute-এ একটি আবশ্যক মান অনুপস্থিত।',
    'current_password' => 'পাসওয়ার্ডটি সঠিক নয়।',
    'date' => ':attribute অবশ্যই একটি সঠিক তারিখ হতে হবে।',
    'date_equals' => ':attribute অবশ্যই :date এর সমান একটি তারিখ হতে হবে।',
    'date_format' => ':attribute অবশ্যই :format ফরম্যাটের সাথে মিলতে হবে।',
    'decimal' => ':attribute-এ অবশ্যই :decimal টি দশমিক ঘর থাকতে হবে।',
    'declined' => ':attribute অবশ্যই প্রত্যাখ্যান করতে হবে।',
    'declined_if' => ':other :value হলে :attribute অবশ্যই প্রত্যাখ্যান করতে হবে।',
    'different' => ':attribute এবং :other অবশ্যই ভিন্ন হতে হবে।',
    'digits' => ':attribute অবশ্যই :digits সংখ্যার হতে হবে।',
    'digits_between' => ':attribute অবশ্যই :min থেকে :max সংখ্যার মধ্যে হতে হবে।',
    'dimensions' => ':attribute-এর ছবির মাপ সঠিক নয়।',
    'distinct' => ':attribute-এ একটি পুনরাবৃত্ত মান রয়েছে।',
    'doesnt_contain' => ':attribute-এ নিম্নলিখিতগুলোর কোনোটি থাকতে পারবে না: :values।',
    'doesnt_end_with' => ':attribute নিম্নলিখিতগুলোর কোনোটি দিয়ে শেষ হতে পারবে না: :values।',
    'doesnt_start_with' => ':attribute নিম্নলিখিতগুলোর কোনোটি দিয়ে শুরু হতে পারবে না: :values।',
    'email' => ':attribute অবশ্যই একটি সঠিক ই-মেইল ঠিকানা হতে হবে।',
    'encoding' => ':attribute অবশ্যই :encoding এনকোডিং-এ হতে হবে।',
    'ends_with' => ':attribute অবশ্যই নিম্নলিখিতগুলোর একটি দিয়ে শেষ হতে হবে: :values।',
    'enum' => 'নির্বাচিত :attribute সঠিক নয়।',
    'exists' => 'নির্বাচিত :attribute সঠিক নয়।',
    'extensions' => ':attribute অবশ্যই নিম্নলিখিত এক্সটেনশনগুলোর একটি হতে হবে: :values।',
    'file' => ':attribute অবশ্যই একটি ফাইল হতে হবে।',
    'filled' => ':attribute-এ একটি মান থাকতে হবে।',
    'gt' => [
        'array' => ':attribute-এ অবশ্যই :value টির বেশি আইটেম থাকতে হবে।',
        'file' => ':attribute অবশ্যই :value কিলোবাইটের বেশি হতে হবে।',
        'numeric' => ':attribute অবশ্যই :value এর বেশি হতে হবে।',
        'string' => ':attribute অবশ্যই :value অক্ষরের বেশি হতে হবে।',
    ],
    'gte' => [
        'array' => ':attribute-এ অবশ্যই :value টি বা তার বেশি আইটেম থাকতে হবে।',
        'file' => ':attribute অবশ্যই :value কিলোবাইট বা তার বেশি হতে হবে।',
        'numeric' => ':attribute অবশ্যই :value বা তার বেশি হতে হবে।',
        'string' => ':attribute অবশ্যই :value অক্ষর বা তার বেশি হতে হবে।',
    ],
    'hex_color' => ':attribute অবশ্যই একটি সঠিক হেক্সাডেসিমেল রং হতে হবে।',
    'image' => ':attribute অবশ্যই একটি ছবি হতে হবে।',
    'in' => 'নির্বাচিত :attribute সঠিক নয়।',
    'in_array' => ':attribute অবশ্যই :other-এ থাকতে হবে।',
    'in_array_keys' => ':attribute-এ নিম্নলিখিত কী-গুলোর অন্তত একটি থাকতে হবে: :values।',
    'integer' => ':attribute অবশ্যই একটি পূর্ণসংখ্যা হতে হবে।',
    'ip' => ':attribute অবশ্যই একটি সঠিক IP ঠিকানা হতে হবে।',
    'ipv4' => ':attribute অবশ্যই একটি সঠিক IPv4 ঠিকানা হতে হবে।',
    'ipv6' => ':attribute অবশ্যই একটি সঠিক IPv6 ঠিকানা হতে হবে।',
    'json' => ':attribute অবশ্যই একটি সঠিক JSON স্ট্রিং হতে হবে।',
    'list' => ':attribute অবশ্যই একটি তালিকা হতে হবে।',
    'lowercase' => ':attribute অবশ্যই ছোট হাতের অক্ষরে হতে হবে।',
    'lt' => [
        'array' => ':attribute-এ অবশ্যই :value টির কম আইটেম থাকতে হবে।',
        'file' => ':attribute অবশ্যই :value কিলোবাইটের কম হতে হবে।',
        'numeric' => ':attribute অবশ্যই :value এর কম হতে হবে।',
        'string' => ':attribute অবশ্যই :value অক্ষরের কম হতে হবে।',
    ],
    'lte' => [
        'array' => ':attribute-এ :value টির বেশি আইটেম থাকতে পারবে না।',
        'file' => ':attribute অবশ্যই :value কিলোবাইট বা তার কম হতে হবে।',
        'numeric' => ':attribute অবশ্যই :value বা তার কম হতে হবে।',
        'string' => ':attribute অবশ্যই :value অক্ষর বা তার কম হতে হবে।',
    ],
    'mac_address' => ':attribute অবশ্যই একটি সঠিক MAC ঠিকানা হতে হবে।',
    'max' => [
        'array' => ':attribute-এ :max টির বেশি আইটেম থাকতে পারবে না।',
        'file' => ':attribute অবশ্যই :max কিলোবাইটের বেশি হতে পারবে না।',
        'numeric' => ':attribute অবশ্যই :max এর বেশি হতে পারবে না।',
        'string' => ':attribute অবশ্যই :max অক্ষরের বেশি হতে পারবে না।',
    ],
    'max_digits' => ':attribute-এ :max টির বেশি সংখ্যা থাকতে পারবে না।',
    'mimes' => ':attribute অবশ্যই নিম্নলিখিত ধরনের একটি ফাইল হতে হবে: :values।',
    'mimetypes' => ':attribute অবশ্যই নিম্নলিখিত ধরনের একটি ফাইল হতে হবে: :values।',
    'min' => [
        'array' => ':attribute-এ অন্তত :min টি আইটেম থাকতে হবে।',
        'file' => ':attribute অবশ্যই অন্তত :min কিলোবাইট হতে হবে।',
        'numeric' => ':attribute অবশ্যই অন্তত :min হতে হবে।',
        'string' => ':attribute অবশ্যই অন্তত :min অক্ষরের হতে হবে।',
    ],
    'min_digits' => ':attribute-এ অন্তত :min সংখ্যা থাকতে হবে।',
    'missing' => ':attribute অনুপস্থিত থাকতে হবে।',
    'missing_if' => ':other :value হলে :attribute অনুপস্থিত থাকতে হবে।',
    'missing_unless' => ':other :value না হলে :attribute অনুপস্থিত থাকতে হবে।',
    'missing_with' => ':values উপস্থিত থাকলে :attribute অনুপস্থিত থাকতে হবে।',
    'missing_with_all' => ':values উপস্থিত থাকলে :attribute অনুপস্থিত থাকতে হবে।',
    'multiple_of' => ':attribute অবশ্যই :value এর গুণিতক হতে হবে।',
    'not_in' => 'নির্বাচিত :attribute সঠিক নয়।',
    'not_regex' => ':attribute-এর ফরম্যাট সঠিক নয়।',
    'numeric' => ':attribute অবশ্যই একটি সংখ্যা হতে হবে।',
    'password' => [
        'letters' => ':attribute-এ অন্তত একটি অক্ষর থাকতে হবে।',
        'mixed' => ':attribute-এ অন্তত একটি বড় হাতের ও একটি ছোট হাতের অক্ষর থাকতে হবে।',
        'numbers' => ':attribute-এ অন্তত একটি সংখ্যা থাকতে হবে।',
        'symbols' => ':attribute-এ অন্তত একটি প্রতীক থাকতে হবে।',
        'uncompromised' => 'দেওয়া :attribute একটি তথ্য-ফাঁসে পাওয়া গেছে। অনুগ্রহ করে ভিন্ন একটি :attribute বেছে নিন।',
    ],
    'present' => ':attribute অবশ্যই উপস্থিত থাকতে হবে।',
    'present_if' => ':other :value হলে :attribute অবশ্যই উপস্থিত থাকতে হবে।',
    'present_unless' => ':other :value না হলে :attribute অবশ্যই উপস্থিত থাকতে হবে।',
    'present_with' => ':values উপস্থিত থাকলে :attribute অবশ্যই উপস্থিত থাকতে হবে।',
    'present_with_all' => ':values উপস্থিত থাকলে :attribute অবশ্যই উপস্থিত থাকতে হবে।',
    'prohibited' => ':attribute নিষিদ্ধ।',
    'prohibited_if' => ':other :value হলে :attribute নিষিদ্ধ।',
    'prohibited_if_accepted' => ':other গৃহীত হলে :attribute নিষিদ্ধ।',
    'prohibited_if_declined' => ':other প্রত্যাখ্যাত হলে :attribute নিষিদ্ধ।',
    'prohibited_unless' => ':other :values-এর মধ্যে না থাকলে :attribute নিষিদ্ধ।',
    'prohibits' => ':attribute থাকলে :other উপস্থিত থাকতে পারবে না।',
    'regex' => ':attribute-এর ফরম্যাট সঠিক নয়।',
    'required' => ':attribute আবশ্যক।',
    'required_array_keys' => ':attribute-এ নিম্নলিখিত এন্ট্রিগুলো থাকতে হবে: :values।',
    'required_if' => ':other :value হলে :attribute আবশ্যক।',
    'required_if_accepted' => ':other গৃহীত হলে :attribute আবশ্যক।',
    'required_if_declined' => ':other প্রত্যাখ্যাত হলে :attribute আবশ্যক।',
    'required_unless' => ':other :values-এর মধ্যে না থাকলে :attribute আবশ্যক।',
    'required_with' => ':values উপস্থিত থাকলে :attribute আবশ্যক।',
    'required_with_all' => ':values উপস্থিত থাকলে :attribute আবশ্যক।',
    'required_without' => ':values উপস্থিত না থাকলে :attribute আবশ্যক।',
    'required_without_all' => ':values-এর কোনোটিই উপস্থিত না থাকলে :attribute আবশ্যক।',
    'same' => ':attribute অবশ্যই :other এর সাথে মিলতে হবে।',
    'size' => [
        'array' => ':attribute-এ অবশ্যই :size টি আইটেম থাকতে হবে।',
        'file' => ':attribute অবশ্যই :size কিলোবাইট হতে হবে।',
        'numeric' => ':attribute অবশ্যই :size হতে হবে।',
        'string' => ':attribute অবশ্যই :size অক্ষরের হতে হবে।',
    ],
    'starts_with' => ':attribute অবশ্যই নিম্নলিখিতগুলোর একটি দিয়ে শুরু হতে হবে: :values।',
    'string' => ':attribute অবশ্যই একটি স্ট্রিং হতে হবে।',
    'timezone' => ':attribute অবশ্যই একটি সঠিক টাইমজোন হতে হবে।',
    'unique' => 'এই :attribute ইতিমধ্যে ব্যবহৃত হয়েছে।',
    'uploaded' => ':attribute আপলোড করা যায়নি।',
    'uppercase' => ':attribute অবশ্যই বড় হাতের অক্ষরে হতে হবে।',
    'url' => ':attribute অবশ্যই একটি সঠিক URL হতে হবে।',
    'ulid' => ':attribute অবশ্যই একটি সঠিক ULID হতে হবে।',
    'uuid' => ':attribute অবশ্যই একটি সঠিক UUID হতে হবে।',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Swaps :attribute for a real Bengali field name on the auth/profile
    | screens this phase covers. Deliberately NOT translating database
    | identifiers, permission slugs or route names — only user-facing field
    | labels that already have a Bengali label sitting next to them in the
    | Blade template, so the validation message matches what the user is
    | actually looking at.
    |
    */

    'attributes' => [
        'name' => 'নাম',
        'email' => 'ই-মেইল',
        'password' => 'পাসওয়ার্ড',
        'password_confirmation' => 'পাসওয়ার্ড নিশ্চিতকরণ',
        'current_password' => 'বর্তমান পাসওয়ার্ড',
    ],

];
