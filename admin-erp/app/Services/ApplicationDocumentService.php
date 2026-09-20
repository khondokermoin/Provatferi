<?php

namespace App\Services;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CV attached to a public application. Deliberately the same checks as
 * NoticeFileService::storeAttachment (extension + magic bytes + libmagic
 * must all agree on PDF), but a separate class because the two differ in
 * everything else: this file is never public at any point, it is streamed
 * only to an admin who holds recruitment.view, and its size ceiling is
 * lower since it is uploaded by an anonymous visitor.
 */
class ApplicationDocumentService
{
    private const DISK = 'uploads_private';

    private const MAX_BYTES = 5 * 1024 * 1024;

    private const FOLDER = 'applications/cv';

    /** ISO 32000-1 §7.5.2: the %PDF- header may appear anywhere in this window. */
    private const HEADER_SCAN_BYTES = 1024;

    /**
     * @throws RuntimeException when the file is not a genuine PDF within the size limit
     */
    public function storeCv(UploadedFile $file): string
    {
        $size = $file->getSize();
        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('সিভির আকার সর্বোচ্চ ৫ MB হতে পারে।');
        }

        if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            throw new RuntimeException('শুধুমাত্র PDF ফাইল গ্রহণযোগ্য।');
        }

        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new RuntimeException('ফাইলটি পড়া যায়নি।');
        }

        /*
         * The PDF specification (ISO 32000-1 §7.5.2) allows the "%PDF-" header
         * anywhere within the first 1024 bytes, and readers are required to
         * accept it there. Requiring it at byte 0 rejected genuine PDFs that
         * carry a UTF-8 BOM or leading whitespace — routinely produced by Word
         * exports and online converters — and that is exactly what broke the
         * live volunteer intake: the CV was refused, and storeFiles() then
         * deleted the photo that had already been stored alongside it.
         *
         * The finfo MIME check below is unchanged, so this only widens WHERE
         * the header may legitimately sit; it does not weaken type detection.
         * A file whose header appears only beyond 1024 bytes is still refused.
         */
        $handle = fopen($realPath, 'rb');
        $head = $handle !== false ? (string) fread($handle, self::HEADER_SCAN_BYTES) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($realPath);
        if (!str_contains($head, '%PDF-') || $detected !== 'application/pdf') {
            throw new RuntimeException('ফাইলটি একটি বৈধ PDF নয়।');
        }

        // The applicant's own filename is discarded: it reaches no directory
        // listing, no header and no disk path.
        $filename = Str::uuid()->toString().'.pdf';
        if (Storage::disk(self::DISK)->putFileAs(self::FOLDER, $file, $filename) === false) {
            throw new RuntimeException('ফাইল সংরক্ষণ করা যায়নি।');
        }

        return self::FOLDER.'/'.$filename;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** Admin-only download. Callers guard with a permission check first. */
    public function response(string $path, string $downloadName, string $mime, bool $inline): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $downloadName, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }
}
