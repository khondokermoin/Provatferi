<?php

/**
 * Bilingual labels for Model "option" constants that are NOT lifecycle
 * statuses (those live in statuses.php instead) — dropdown/label vocabularies
 * like notice types, employment types, or application field names.
 *
 * IMPORTANT: several of the constants these mirror (Notice::TYPES,
 * JobPosting::CONFIGURABLE_APPLICATION_FIELDS, JobPosting::VOLUNTEER_NOTE,
 * JobApplication::PREFERRED_CONTACTS) are ALSO read directly by public API
 * controllers (Api/V1/Public/*, Api/V1/JobPostingController) that serve
 * provatferi.org — a completely different, Bangla-only-by-default surface
 * with its own i18n architecture (DB `_en` columns), unrelated to the admin
 * panel's locale. This file and option_label()/option_options() are used
 * ONLY from admin controllers/views to build a locale-aware display array
 * alongside the untouched raw constant — never from the public API path,
 * and the constants themselves are never edited to call __() directly,
 * so the public site's output is completely unaffected by this catalogue.
 *
 * JobApplication::SKILLS is deliberately excluded: its vocabulary is already
 * a fixed, largely-English technical term list by established convention
 * (e.g. "Fundraising / Donation / Sponsorship", "Project Management") that
 * reads the same in either admin language — not a translation gap.
 */
return [
    'notice_types' => [
        'general' => 'সাধারণ বিজ্ঞপ্তি',
        'urgent' => 'জরুরি বিজ্ঞপ্তি',
        'recruitment' => 'নিয়োগ বিজ্ঞপ্তি',
        'volunteer' => 'স্বেচ্ছাসেবী আহ্বান',
        'event' => 'অনুষ্ঠান/কর্মসূচি',
        'registration' => 'নিবন্ধন বিজ্ঞপ্তি',
        'tender' => 'দরপত্র',
        'result' => 'ফলাফল',
        'announcement' => 'ঘোষণা',
        'other' => 'অন্যান্য',
    ],
    'employment_types' => [
        'full_time' => 'পূর্ণকালীন',
        'part_time' => 'খণ্ডকালীন',
        'volunteer' => 'স্বেচ্ছাসেবী',
        'contract' => 'চুক্তিভিত্তিক',
    ],
    'application_modes' => [
        'fixed' => 'নির্দিষ্ট সময়সীমা',
        'rolling' => 'চলমান',
    ],
    'campaign_types' => [
        'regular' => 'নিয়মিত',
        'special' => 'বিশেষ',
    ],
    'application_field_labels' => [
        'photo' => 'প্রোফাইল ছবি',
        'cv' => 'সিভি / রেজিউমে',
        'availability' => 'সপ্তাহে সময় দিতে পারবেন',
        'experience' => 'কাজের অভিজ্ঞতা',
        'contribution' => 'অবদানের পরিকল্পনা',
        'skills' => 'আগ্রহ ও দক্ষতার ক্ষেত্র',
        'district' => 'জেলা',
        'current_location' => 'বর্তমান অবস্থান',
        'profession' => 'পেশা / শিক্ষা',
        'preferred_contact' => 'পছন্দের যোগাযোগ মাধ্যম',
    ],
    'field_requirement_levels' => [
        'required' => 'আবশ্যক',
        'optional' => 'ঐচ্ছিক',
    ],
    'preferred_contacts' => [
        'phone' => 'ফোন কল',
        'whatsapp' => 'WhatsApp',
        'email' => 'ই-মেইল',
    ],
    'volunteer_note' => 'এটি একটি স্বেচ্ছাসেবী সুযোগ; বর্তমানে আর্থিক পারিশ্রমিকের প্রতিশ্রুতি নেই।',
    'committee_types' => [
        'executive' => 'নির্বাহী কমিটি',
        'advisory' => 'উপদেষ্টা পরিষদ',
        'sub' => 'উপ-কমিটি',
        'ad_hoc' => 'আহ্বায়ক কমিটি',
    ],
    'unit_types' => [
        'central' => 'কেন্দ্রীয়',
        'division' => 'বিভাগ',
        'district' => 'জেলা',
        'upazila' => 'উপজেলা',
        'union' => 'ইউনিয়ন',
        'branch' => 'শাখা',
        'unit' => 'ইউনিট',
    ],
];
