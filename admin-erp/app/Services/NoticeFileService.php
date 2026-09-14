<?php

namespace App\Services;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Notice attachments and cover images live on the private disk under
 * server-generated names and are never copied to a public location. They are
 * streamed through a route that re-checks the notice's visibility on every
 * request, so a file is public exactly while its notice is — there is no
 * promoted copy that could outlive an unpublish.
 */
class NoticeFileService
{
    private const DISK = 'uploads_private';

    private const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;

    private const IMAGE_MIMES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __construct(private readonly PhotoUploadService $photos)
    {
    }

    /** Image checks (extension, header sniffing, full decode) are shared with every other upload. */
    public function storeCover(UploadedFile $file): string
    {
        return $this->photos->storePrivate($file, 'notices/covers');
    }

    /**
     * @return array{path: string, mime: string, size: int}
     *
     * @throws RuntimeException when the file is not a genuine PDF within the size limit
     */
    public function storeAttachment(UploadedFile $file): array
    {
        $size = $file->getSize();
        if ($size === false || $size === 0 || $size > self::ATTACHMENT_MAX_BYTES) {
            throw new RuntimeException('সংযুক্তির আকার সর্বোচ্চ ১০ MB হতে পারে।');
        }

        if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            throw new RuntimeException('শুধুমাত্র PDF সংযুক্তি গ্রহণযোগ্য।');
        }

        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new RuntimeException('ফাইলটি পড়া যায়নি।');
        }

        // Both the magic bytes and libmagic's verdict must agree; a renamed
        // script or HTML file with a .pdf extension fails one or the other.
        $handle = fopen($realPath, 'rb');
        $magic = $handle !== false ? (string) fread($handle, 5) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($realPath);
        if ($magic !== '%PDF-' || $detected !== 'application/pdf') {
            throw new RuntimeException('ফাইলটি একটি বৈধ PDF নয়।');
        }

        $filename = Str::uuid()->toString().'.pdf';
        if (Storage::disk(self::DISK)->putFileAs('notices/attachments', $file, $filename) === false) {
            throw new RuntimeException('সংযুক্তি সংরক্ষণ করা যায়নি।');
        }

        return ['path' => 'notices/attachments/'.$filename, 'mime' => 'application/pdf', 'size' => (int) $size];
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function coverMime(string $path): string
    {
        return self::IMAGE_MIMES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    public function response(string $path, string $downloadName, string $mime, bool $inline): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $downloadName, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'public, max-age=300',
        ], $inline ? 'inline' : 'attachment');
    }
}
