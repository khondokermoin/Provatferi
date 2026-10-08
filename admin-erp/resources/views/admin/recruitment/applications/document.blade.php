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

    BILINGUAL DOCUMENT (Phase 3 Step 4): the caller (JobApplicationController
    print()/pdf()) sets App::setLocale() to the requested document language
    (?doclang=bn|en, defaulting to the admin's own current locale) BEFORE
    building $contactLabels and rendering this view — every __()/option_label()
    call below, and admin_datetime()'s digit/month-name choice, therefore follows
    that document language, completely independent of the admin's own UI
    locale for the rest of the panel. What must NEVER be translated stays
    untouched here: every literal $application->applicant_* / district /
    current_location / profession / availability / experience / contribution /
    other_skills read is the applicant's own submitted text, shown verbatim in
    whichever language they wrote it in — the same rule as
    admin.bilingual.applicant_note. skillLabels() is also left alone in either
    document language, per the established, pre-existing policy that
    JobApplication::SKILLS is a fixed, largely-English technical vocabulary,
    not a translation gap (see StatusAndOptionHelpersTest's sibling decision
    for option_label()/status_label() call sites elsewhere in admin).
--}}
<style>
    body { font-family: notosansbengali, sans-serif; font-size: 10.5pt; color: #201B17; line-height: 1.5; }
    /* One mark, one identity block: the logo appears exactly once, to the
       left of the org name in both languages — no repetition of either
       elsewhere in the document (§4 of the 2026-09-24 redesign). */
    .doc-header { width: 100%; margin-bottom: 0; }
    .doc-header td { vertical-align: middle; }
    .doc-header .org-name-bn { font-family: notosansbengali, sans-serif; font-weight: bold; font-size: 14pt; color: #201B17; line-height: 1.3; }
    .doc-header .org-name-en { font-size: 9pt; color: #6B5F53; margin-top: 1px; }
    .doc-header .doc-title { font-family: notosansbengali, sans-serif; font-size: 9.5pt; color: #AC350A; font-weight: bold; margin-top: 4px; }
    .doc-divider { border: none; border-top: 1px solid #E6DFD5; margin: 10px 0 14px; }
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

{{-- Logo appears exactly once, here, for the whole document. --}}
<table class="doc-header">
    <tr>
        <td style="width: 62px;">
            @if ($logoSrc)
                <img src="{{ $logoSrc }}" style="height: 42px;" alt="">
            @endif
        </td>
        <td>
            <div class="org-name-bn">প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র</div>
            <div class="org-name-en">Provatferi Literary and Cultural Center</div>
            <div class="doc-title">{{ __('admin.document.doc_title') }}</div>
        </td>
    </tr>
</table>
<hr class="doc-divider">

<table class="doc-meta">
    <tr>
        <td class="meta-label">{{ __('admin.document.application_no') }}</td>
        <td><strong>{{ $application->application_no }}</strong></td>
        <td class="meta-label" style="width: 110px;">{{ __('admin.fields.notice') }}</td>
        <td>{{ $application->jobPosting?->title ?? '—' }}</td>
    </tr>
    <tr>
        <td class="meta-label">{{ __('admin.fields.submitted_at') }}</td>
        <td colspan="3">{{ admin_datetime($application->submitted_at ?? $application->created_at) }}</td>
    </tr>
</table>

<table class="applicant-block">
    <tr>
        <td class="photo-cell">
            @if ($photoSrc)
                <img src="{{ $photoSrc }}" alt="">
            @else
                <div class="photo-placeholder">{{ __('admin.fields.no_photo') }}</div>
            @endif
        </td>
        <td class="name-cell">
            <div class="applicant-name">{{ $application->applicant_name }}</div>
        </td>
    </tr>
</table>

<table class="fields">
    <tr><th>{{ __('admin.fields.mobile') }}</th><td>{{ $application->applicant_phone ?: '—' }}</td></tr>
    <tr><th>{{ __('admin.common.email') }}</th><td>{{ $application->applicant_email }}</td></tr>
    <tr><th>{{ option_label('application_field_labels', 'preferred_contact') }}</th><td>{{ $contactLabels[$application->preferred_contact] ?? '—' }}</td></tr>
    <tr><th>{{ __('admin.fields.district') }}</th><td>{{ $application->district ?: '—' }}</td></tr>
    <tr><th>{{ option_label('application_field_labels', 'current_location') }}</th><td>{{ $application->current_location ?: '—' }}</td></tr>
    <tr><th>{{ option_label('application_field_labels', 'profession') }}</th><td>{{ $application->profession ?: '—' }}</td></tr>
    <tr><th>{{ option_label('application_field_labels', 'availability') }}</th><td>{{ $application->availability ?: '—' }}</td></tr>
</table>

@php $skillLabels = $application->skillLabels(); @endphp
@if ($skillLabels !== [] || $application->other_skills)
    <div class="section-title">{{ option_label('application_field_labels', 'skills') }}</div>
    @if ($skillLabels !== [])
        <div>
            @foreach ($skillLabels as $label)
                <span class="skill-badge">{{ $label }}</span>
            @endforeach
        </div>
    @endif
    @if ($application->other_skills)
        <p class="long-text">{{ __('admin.document.other_prefix') }}: {{ $application->other_skills }}</p>
    @endif
@endif

@if ($application->experience)
    <div class="section-title">{{ option_label('application_field_labels', 'experience') }}</div>
    <p class="long-text">{{ $application->experience }}</p>
@endif

@if ($application->contribution)
    <div class="section-title">{{ __('admin.document.contribution_heading') }}</div>
    <p class="long-text">{{ $application->contribution }}</p>
@endif

<div class="section-title">{{ __('admin.fields.attachment') }}</div>
<table class="fields">
    <tr><th>{{ option_label('application_field_labels', 'cv') }}</th><td>{{ $application->cv_path ? __('admin.document.attached') : __('admin.document.not_provided') }}</td></tr>
</table>

<div class="section-title">{{ __('admin.document.declarations_and_consent') }}</div>
<ul class="consent-list">
    @foreach (['accuracy_declaration', 'privacy_consent', 'contact_consent'] as $field)
        <li class="{{ $application->{$field} ? 'consent-yes' : 'consent-no' }}">
            {{ $application->{$field} ? '✓' : '✗' }} {{ __('admin.document.'.$field) }}
        </li>
    @endforeach
</ul>

<div class="doc-footer">
    {{ __('admin.document.generated_footer', ['datetime' => admin_datetime($generatedAt)]) }}
</div>
