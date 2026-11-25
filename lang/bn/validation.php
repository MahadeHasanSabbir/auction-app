<?php

// Bangla messages for the rules the public forms use (sign-up, sign-in,
// contact, bidding). Rules not listed here fall back to English.

return [
    'accepted' => ':attribute গ্রহণ করতে হবে।',
    'confirmed' => ':attribute মিলছে না।',
    'current_password' => 'পাসওয়ার্ড সঠিক নয়।',
    'digits' => ':attribute অবশ্যই :digits অঙ্কের হতে হবে।',
    'email' => ':attribute একটি সঠিক ইমেইল ঠিকানা হতে হবে।',
    'exists' => 'নির্বাচিত :attribute সঠিক নয়।',
    'in' => 'নির্বাচিত :attribute সঠিক নয়।',
    'integer' => ':attribute একটি পূর্ণসংখ্যা হতে হবে।',
    'lowercase' => ':attribute ছোট হাতের অক্ষরে হতে হবে।',
    'max' => [
        'numeric' => ':attribute :max-এর বেশি হতে পারবে না।',
        'string' => ':attribute :max অক্ষরের বেশি হতে পারবে না।',
        'file' => ':attribute :max কিলোবাইটের বেশি হতে পারবে না।',
        'array' => ':attribute-এ :max-টির বেশি থাকতে পারবে না।',
    ],
    'min' => [
        'numeric' => ':attribute কমপক্ষে :min হতে হবে।',
        'string' => ':attribute কমপক্ষে :min অক্ষরের হতে হবে।',
        'file' => ':attribute কমপক্ষে :min কিলোবাইট হতে হবে।',
        'array' => ':attribute-এ কমপক্ষে :min-টি থাকতে হবে।',
    ],
    'numeric' => ':attribute একটি সংখ্যা হতে হবে।',
    'password' => [
        'letters' => ':attribute-এ কমপক্ষে একটি অক্ষর থাকতে হবে।',
        'mixed' => ':attribute-এ কমপক্ষে একটি বড় হাতের ও একটি ছোট হাতের অক্ষর থাকতে হবে।',
        'numbers' => ':attribute-এ কমপক্ষে একটি সংখ্যা থাকতে হবে।',
        'symbols' => ':attribute-এ কমপক্ষে একটি চিহ্ন থাকতে হবে।',
        'uncompromised' => 'এই :attribute একটি ফাঁস হওয়া তথ্যভান্ডারে পাওয়া গেছে। অন্য একটি :attribute বেছে নিন।',
    ],
    'regex' => ':attribute-এর ফরম্যাট সঠিক নয়।',
    'required' => ':attribute দিতে হবে।',
    'required_if' => ':other :value হলে :attribute দিতে হবে।',
    'size' => [
        'string' => ':attribute অবশ্যই :size অক্ষরের হতে হবে।',
    ],
    'string' => ':attribute লেখা হতে হবে।',
    'unique' => 'এই :attribute আগেই ব্যবহার হয়েছে।',
    'url' => ':attribute একটি সঠিক লিংক হতে হবে।',

    'attributes' => [
        'name' => 'নাম',
        'email' => 'ইমেইল',
        'password' => 'পাসওয়ার্ড',
        'phone' => 'ফোন',
        'company_name' => 'কোম্পানির নাম',
        'code' => 'কোড',
        'amount' => 'পরিমাণ',
        'subject' => 'বিষয়',
        'message' => 'বার্তা',
        'reason' => 'কারণ',
    ],
];
