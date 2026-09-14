<?php

namespace App\Services;

use App\Models\JobPosting;
use App\Models\Notice;
use App\Models\User;

/**
 * One job posting has at most one notice. Sync rule, kept deliberately simple:
 *
 * - Copy fields (title, summary, body, organization unit) follow the posting
 *   only while `syncs_from_job_posting` is true.
 * - Editing any of that copy on the notice itself switches sync off, so a
 *   hand-written notice is never silently overwritten by a later posting edit.
 * - Everything notice-specific — type, status, publication date, pinning,
 *   attachments, action link — is owned by the notice and never synced.
 * - Employment terms (type, deadline, salary, application mode) are never
 *   copied at all; public pages read them live from the posting.
 */
class NoticeRecruitmentLinker
{
    public function createFor(JobPosting $job, User $actor): Notice
    {
        $existing = $job->notice()->first();
        if ($existing !== null) {
            return $existing;
        }

        $publishNow = $job->status === 'open' && $actor->can('notices.publish');
        $publishedAt = $publishNow ? ($job->published_at ?? now()) : null;

        $notice = new Notice([
            'notice_type' => $job->isVolunteer() ? 'volunteer' : 'recruitment',
            'status' => $publishNow ? 'published' : 'draft',
            'published_at' => $publishedAt,
            'is_pinned' => false,
            'syncs_from_job_posting' => true,
        ]);
        $this->copyFrom($notice, $job);
        $notice->job_posting_id = $job->id;
        $notice->slug = Notice::uniqueSlug($job->slug);
        $notice->forceFill([
            'first_published_at' => $publishedAt,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $notice->save();

        return $notice;
    }

    public function syncFrom(JobPosting $job, ?User $actor = null): void
    {
        $notice = $job->notice()->first();
        if ($notice === null || ! $notice->syncs_from_job_posting) {
            return;
        }

        $this->copyFrom($notice, $job);
        if ($notice->isDirty()) {
            if ($actor !== null) {
                $notice->updated_by = $actor->id;
            }
            $notice->save();
        }
    }

    public function copyFrom(Notice $notice, JobPosting $job): void
    {
        $notice->title = $job->title;
        $notice->summary = $job->summary;
        $notice->body = Notice::bodyFromJobPosting($job);
        $notice->organization_unit_id = $job->organization_unit_id;
    }
}
