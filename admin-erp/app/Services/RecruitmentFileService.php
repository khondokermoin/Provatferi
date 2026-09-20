<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §12: the recruitment-posting counterpart to NoticeFileService — job
 * postings never had an image field of any kind before this. Same private
 * disk, same server-generated filenames, same validation (delegated to
 * PhotoUploadService, so a renamed script or a corrupted image is refused
 * exactly as it would be anywhere else in this app), same header set on the
 * served response.
 */
class RecruitmentFileService
{
    private const DISK = 'uploads_private';

    private const IMAGE_MIMES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __construct(private readonly PhotoUploadService $photos)
    {
    }

    public function storeShareImage(UploadedFile $file): string
    {
        return $this->photos->storePrivate($file, 'job_postings/share');
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function mime(string $path): string
    {
        return self::IMAGE_MIMES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    public function response(string $path, string $downloadName, string $mime): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $downloadName, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'public, max-age=300',
        ], 'inline');
    }
}
