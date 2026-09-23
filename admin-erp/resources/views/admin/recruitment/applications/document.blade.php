{{--
    Shared printable-application content — used AS-IS by both the print
    route (rendered directly in-browser, wrapped by layouts/print.blade.php)
    and the PDF route (this same markup handed to RecruitmentPdfService as
    a raw HTML string, no browser involved). Table/block layout only — mPDF
    has no flexbox/grid support, so this deliberately does not use the
    admin panel's usual Bootstrap classes.

    $application, $contactLabels — same as the admin detail page.
    $photoSrc  — fully-resolved <img src>, or null. The CALLER resolves this
                 (a data: URI for the PDF route, the authenticated file route
                 for the print route) — this partial never decides how a
                 photo is fetched, only whether one exists.
    $logoSrc   — fully-resolved <img src> for the org mark, same reasoning.
    $generatedAt — Carbon instant this document was produced.

    Deliberately NOT shown, per policy: internal_note, interview_*,
    reviewed_by, status, id/job_posting_id, any storage path, skill KEYS
    (labels only). If a CV exists, its presence is noted — the file itself
    is never embedded.
--}}
<style>
    body { font-family: notosansbengali, sans-serif; font-size: 10.5pt; color: #201B17; line-height: 1.5; }
    .doc-header { width: 100%; border-bottom: 2px solid #AC350A; padding-bottom: 10px; margin-bottom: 14px; }
    .doc-header td { vertical-align: middle; }
    .doc-header .org-name { font-family: notosansbengali, sans-serif; font-weight: bold; font-size: 15pt; color: #201B17; }
    .doc-header .doc-title { font-size: 10pt; color: #6B5F53; margin-top: 2px; }
    .doc-meta { width: 100%; margin-bottom: 14px; }
    .doc-meta td { font-size: 10pt; padding: 2px 0; }
    .doc-meta .meta-label { color: #6B5F53; width: 110px; }
    .applicant-block { width: 100%; margin-bottom: 16px; }
    .applicant-block .photo-cell { width: 92px; vertical-align: top; }
    .applicant-block .photo-cell img { width: 80px; height: 80px; object-fit: cover; border: 1px solid #E6DFD5; border-radius: 4px; }
    .applicant-block .photo-cell .photo-placeholder {
        width: 80px; height: 80px; border: 1px solid #E6DFD5; border-radius: 4px;
        text-align: center; color: #A89F94; font-size: 8pt; padding-top: 30px;
    }
    .applicant-block .name-cell { vertical-align: top; padding-left: 14px; }
    .applicant-block .applicant-name { font-family: notosansbengali, sans-serif; font-weight: bold; font-size: 13pt; }
    .applicant-block .app-no { color: #6B5F53; font-size: 9.5pt; margin-top: 2px; }

    table.fields { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.fields th, table.fields td { border: 1px solid #E6DFD5; padding: 6px 8px; text-align: left; vertical-align: top; font-size: 10pt; }
    table.fields th { width: 32%; background-color: #FAF7F2; font-weight: bold; color: #4A4038; }

    .section-title { font-family: notosansbengali, sans-serif; font-weight: bold; font-size: 11pt; margin: 14px 0 6px; color: #201B17; }
    .skill-badge { display: inline-block; border: 1px solid #E6DFD5; border-radius: 10px; padding: 2px 8px; margin: 0 4px 4px 0; font-size: 9pt; background-color: #FAF7F2; }
    .long-text { white-space: pre-line; font-size: 10pt; }

    .consent-list { list-style: none; padding: 0; margin: 0; font-size: 9.5pt; }
    .consent-list li { padding: 2px 0; }
    .consent-yes { color: #1A7A3C; }
    .consent-no { color: #A89F94; }

    .doc-footer { margin-top: 20px; padding-top: 8px; border-top: 1px solid #E6DFD5; font-size: 8.5pt; color: #A89F94; }
</style>

<table class="doc-header">
    <tr>
        <td style="width: 70px;">
            @if ($logoSrc)
                <img src="{{ $logoSrc }}" style="height: 46px;" alt="">
            @endif
        </td>
        <td>
            <div class="org-name">প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র</div>
            <div class="doc-title">স্বেচ্ছাসেবী আবেদনপত্র</div>
        </td>
    </tr>
</table>

<table class="doc-meta">
    <tr>
        <td class="meta-label">আবেদন নম্বর</td>
        <td><strong>{{ $application->application_no }}</strong></td>
        <td class="meta-label" style="width: 110px;">বিজ্ঞপ্তি</td>
        <td>{{ $application->jobPosting?->title ?? '—' }}</td>
    </tr>
    <tr>
        <td class="meta-label">জমার তারিখ/সময়</td>
        <td colspan="3">{{ bn_datetime($application->submitted_at ?? $application->created_at) }}</td>
    </tr>
</table>

<table class="applicant-block">
    <tr>
        <td class="photo-cell">
            @if ($photoSrc)
                <img src="{{ $photoSrc }}" alt="">
            @else
                <div class="photo-placeholder">ছবি নেই</div>
            @endif
        </td>
        <td class="name-cell">
            <div class="applicant-name">{{ $application->applicant_name }}</div>
            <div class="app-no">{{ $application->profession ?: '' }}</div>
        </td>
    </tr>
</table>

<table class="fields">
    <tr><th>মোবাইল</th><td>{{ $application->applicant_phone ?: '—' }}</td></tr>
    <tr><th>ই-মেইল</th><td>{{ $application->applicant_email }}</td></tr>
    <tr><th>পছন্দের যোগাযোগ মাধ্যম</th><td>{{ $contactLabels[$application->preferred_contact] ?? '—' }}</td></tr>
    <tr><th>জেলা</th><td>{{ $application->district ?: '—' }}</td></tr>
    <tr><th>বর্তমান অবস্থান</th><td>{{ $application->current_location ?: '—' }}</td></tr>
    <tr><th>পেশা / শিক্ষা</th><td>{{ $application->profession ?: '—' }}</td></tr>
    <tr><th>সপ্তাহে সময় দিতে পারবেন</th><td>{{ $application->availability ?: '—' }}</td></tr>
</table>

@php $skillLabels = $application->skillLabels(); @endphp
@if ($skillLabels !== [] || $application->other_skills)
    <div class="section-title">আগ্রহ ও দক্ষতার ক্ষেত্র</div>
    @if ($skillLabels !== [])
        <div>
            @foreach ($skillLabels as $label)
                <span class="skill-badge">{{ $label }}</span>
            @endforeach
        </div>
    @endif
    @if ($application->other_skills)
        <p class="long-text">অন্যান্য: {{ $application->other_skills }}</p>
    @endif
@endif

@if ($application->experience)
    <div class="section-title">কাজের অভিজ্ঞতা</div>
    <p class="long-text">{{ $application->experience }}</p>
@endif

@if ($application->contribution)
    <div class="section-title">প্রভাতফেরীতে যেভাবে অবদান রাখতে চান</div>
    <p class="long-text">{{ $application->contribution }}</p>
@endif

<div class="section-title">সংযুক্তি</div>
<table class="fields">
    <tr><th>সিভি / রেজিউমে</th><td>{{ $application->cv_path ? 'সংযুক্ত আছে' : 'প্রদান করা হয়নি' }}</td></tr>
</table>

<div class="section-title">ঘোষণা ও সম্মতি</div>
<ul class="consent-list">
    @foreach (['accuracy_declaration' => 'তথ্যের সঠিকতার ঘোষণা', 'privacy_consent' => 'গোপনীয়তা নীতিতে সম্মতি', 'contact_consent' => 'যোগাযোগের অনুমতি'] as $field => $label)
        <li class="{{ $application->{$field} ? 'consent-yes' : 'consent-no' }}">
            {{ $application->{$field} ? '✓' : '✗' }} {{ $label }}
        </li>
    @endforeach
</ul>

<div class="doc-footer">
    এই নথিটি Provatferi ERP থেকে {{ bn_datetime($generatedAt) }}-এ তৈরি হয়েছে — শুধুমাত্র অভ্যন্তরীণ পর্যালোচনার জন্য।
</div>
