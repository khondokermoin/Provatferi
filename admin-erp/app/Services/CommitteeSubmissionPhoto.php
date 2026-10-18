<?php

namespace App\Services;

use App\Models\CommitteeSubmission;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The applicant's photo of a committee application, for the REVIEWER (never the public).
 *
 * Every committee photo lives in two places (PhotoUploadService, §38): the ORIGINAL on the private disk, written when the
 * application is filed, and a PUBLIC copy that an approval makes for the website. The review page used to look only at the
 * public copy, so while an application was still waiting — the one moment a reviewer needs the picture — it showed a grey
 * placeholder. The original is private and has no URL, so the review page now asks THIS class for it and shows it through an
 * authenticated route that streams the bytes (CommitteeSubmissionController::photo), the same way the membership
 * application's photo is shown. Nothing here makes any file public.
 *
 * A path read from the database is never trusted: it is opened only when it has the exact shape this application itself
 * writes (one known folder, one plain file name, an image extension). `..`, a leading slash, a backslash, a NUL byte, a nested
 * folder, another private folder (CVs live next door) and a script name all fail that test and are treated as "no file".
 */
class CommitteeSubmissionPhoto
{
    /** Nothing was submitted. */
    public const NONE = 'none';

    /** A file can be read and shown. */
    public const AVAILABLE = 'available';

    /** A path is on record but no readable image is behind it (deleted, moved, or not a path this application would write). */
    public const MISSING = 'missing';

    private const PRIVATE_DISK = 'uploads_private';

    private const PUBLIC_DISK = 'public';

    private const PRIVATE_PATH = '#^committee-submissions/[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(?:jpe?g|png|webp)\z#i';

    private const PUBLIC_PATH = '#^committee/[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(?:jpe?g|png|webp)\z#i';

    private const IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * The best readable source for the review page: the private original, else the public copy an approval made.
     *
     * @return array{state: string, disk: ?string, path: ?string}
     */
    public function locate(CommitteeSubmission $submission): array
    {
        $original = $this->privateOriginal($submission);
        if ($original !== null) {
            return ['state' => self::AVAILABLE, 'disk' => self::PRIVATE_DISK, 'path' => $original];
        }

        $copy = (string) $submission->photo_approved_path;
        if ($copy !== '' && $this->readable(self::PUBLIC_DISK, $copy, self::PUBLIC_PATH)) {
            return ['state' => self::AVAILABLE, 'disk' => self::PUBLIC_DISK, 'path' => $copy];
        }

        $onRecord = (string) $submission->photo_path !== '' || $copy !== '';

        return ['state' => $onRecord ? self::MISSING : self::NONE, 'disk' => null, 'path' => null];
    }

    /** The private original's path when it is a path this application would write AND the file is there; otherwise null. */
    public function privateOriginal(CommitteeSubmission $submission): ?string
    {
        $path = (string) $submission->photo_path;

        return $path !== '' && $this->readable(self::PRIVATE_DISK, $path, self::PRIVATE_PATH) ? $path : null;
    }

    /** Streams the photo to a reviewer. 404 when there is nothing safe to show. */
    public function response(CommitteeSubmission $submission): StreamedResponse
    {
        $found = $this->locate($submission);
        abort_unless($found['state'] === self::AVAILABLE, 404);

        $disk = Storage::disk($found['disk']);
        $mime = $this->sniff($disk, $found['path']);
        abort_unless($mime !== null, 404);

        // The download name is generic: neither the stored name nor anything the applicant typed reaches a header.
        return $disk->response($found['path'], "applicant-photo-{$submission->id}.".self::IMAGE_MIMES[$mime], [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    private function readable(string $disk, string $path, string $pattern): bool
    {
        return preg_match($pattern, $path) === 1 && Storage::disk($disk)->exists($path);
    }

    /** What the file really is (its first bytes), not what its name or the database says. Null unless a JPEG, PNG or WebP. */
    private function sniff(Filesystem $disk, string $path): ?string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }
        $head = (string) fread($stream, 4096);
        fclose($stream);

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return is_string($mime) && array_key_exists($mime, self::IMAGE_MIMES) ? $mime : null;
    }
}
