<?php

/**
 * Phase 3 — the ADM-002 status-label map, moved out of app/helpers.php so the
 * same slug can render in either admin UI language.
 *
 * The SLUGS are unchanged real database values, form values and Rule::in()
 * targets. Only the human-readable text lives here; nothing in this file is
 * ever written to the database or compared against.
 */
return [
    'active' => 'সক্রিয়',
    'inactive' => 'নিষ্ক্রিয়',
    'draft' => 'খসড়া',
    'published' => 'প্রকাশিত',
    'archived' => 'সংরক্ষিত',
    'open' => 'খোলা',
    'closed' => 'বন্ধ',
    'suspended' => 'স্থগিত',
    'expired' => 'মেয়াদোত্তীর্ণ',
    'pending' => 'পর্যালোচনার অপেক্ষায়',
    'under_review' => 'পর্যালোচনাধীন',
    'need_information' => 'তথ্য প্রয়োজন',
    'approved' => 'অনুমোদিত',
    'rejected' => 'প্রত্যাখ্যাত',
    'cancelled' => 'বাতিল',
    'submitted' => 'জমাকৃত',
    'shortlisted' => 'বাছাইকৃত',
    'selected' => 'নির্বাচিত',
    'contacted' => 'যোগাযোগ করা হয়েছে',
    'accepted' => 'গৃহীত',
    'not_selected' => 'নির্বাচিত হয়নি',
    'withdrawn' => 'প্রত্যাহৃত',
    'scheduled' => 'নির্ধারিত',
    'upcoming' => 'আসন্ন',
    'completed' => 'সমাপ্ত',
    'correction_requested' => 'সংশোধন প্রয়োজন',
    'unpublished' => 'অপ্রকাশিত',
    'paid' => 'পরিশোধিত',
];
