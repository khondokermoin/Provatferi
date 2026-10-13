<?php
/**
 * Server-side half of the Bengali PDF acceptance run (docs/PDF_BENGALI_STANDARD.md): disposable recruitment applications for the
 * PDF route — one with an ordinary photo and one with the kind of photo that used to make the PDF answer HTTP 500 (a ~4.5 MB phone
 * photo; the application form accepts up to 5 MB). The browser half is institutional/scripts/pdf-qa.mjs; the receipts of the same
 * run come from membership-registry-qa.php `receipt-setup` and institutional/scripts/membership-receipts-qa.mjs. CLI only: on
 * production it is uploaded to the admin docroot under a ONE-OFF name `_qa_<hex>.php` and run from a cron (it answers 404 to
 * anything but the CLI); locally `php deploy/qa/pdf-qa.php <mode>`.
 *
 *   setup      idempotent: one disposable job posting and two applications, labelled "QA PDF TEST" in their e-mail address
 *              (qa-pdf-test-*@example.test) and posting slug (qa-pdf-test-*), with a photo on the private disk each. Application
 *              numbers are QA-PDF-… — the real VOL- numbering is not consumed. Prints JSON: the PDF path of each application.
 *   inspect    READ-ONLY. The QA rows and, for each photo, whether it exists, its size and sha256.
 *   sweep      READ-ONLY. Anything still carrying the QA marker.
 *   cleanup go (`go` is required; on production only from a kit named _qa_<hex>.php made for this session) removes the QA
 *              applications, their photos and the posting, then proves nothing is left.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', 'stderr');
set_time_limit(0);

$mode = $argv[1] ?? 'inspect';

// Same production gate as the other kits: a destructive mode runs only from a kit uploaded under a one-off name for THIS session.
if ($mode === 'cleanup' && (is_dir('/home/u951246149/domains') || getenv('QA_FORCE_PRODUCTION_GUARDS') === '1')
    && ! preg_match('/^_qa_(?:[a-z]+_)?[0-9a-f]{8,}\.php$/', basename(__FILE__))) {
    fwrite(STDERR, json_encode(['mode' => 'cleanup', 'ok' => false, 'error' => 'refusing on production: a destructive mode runs only from a kit named _qa_<hex>.php made for this session (this file is '.basename(__FILE__).') — nothing was removed'], JSON_UNESCAPED_SLASHES)."\n");
    exit(2);
}
$APP = getenv('QA_APP') ?: (is_dir('/home/u951246149/domains/provatferi.org/laravel-admin') ? '/home/u951246149/domains/provatferi.org/laravel-admin' : dirname(__DIR__, 2));
define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$app = require $APP.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Storage;

const QA_SLUG_PREFIX = 'qa-pdf-test-';
const QA_EMAIL_LIKE = 'qa-pdf-test-%@example.test';

$disk = Storage::disk('uploads_private');
$out = static function (array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
};

/** A JPEG of random pixels built from a tile (incompressible: the heaviest photo of its size), cheap to make. */
$noiseJpeg = static function (int $width, int $height, int $quality): string {
    $tile = imagecreatetruecolor(256, 256);
    for ($x = 0; $x < 256; $x++) {
        for ($y = 0; $y < 256; $y++) {
            imagesetpixel($tile, $x, $y, (int) imagecolorallocate($tile, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
    }
    $image = imagecreatetruecolor($width, $height);
    for ($x = 0; $x < $width; $x += 256) {
        for ($y = 0; $y < $height; $y += 256) {
            imagecopy($image, $tile, $x, $y, 0, 0, 256, 256);
        }
    }
    ob_start();
    imagejpeg($image, null, $quality);

    return (string) ob_get_clean();
};

/** An ordinary portrait-ish photo: smooth gradient, a face-like oval, a little sensor noise. */
$normalJpeg = static function (): string {
    $image = imagecreatetruecolor(480, 480);
    for ($y = 0; $y < 480; $y++) {
        imageline($image, 0, $y, 479, $y, (int) imagecolorallocate($image, 120 + intdiv($y, 6), 150 + intdiv($y, 12), 175));
    }
    imagefilledellipse($image, 240, 200, 190, 230, (int) imagecolorallocate($image, 232, 196, 164));
    imagefilledrectangle($image, 110, 360, 370, 480, (int) imagecolorallocate($image, 55, 85, 125));
    for ($i = 0; $i < 6000; $i++) {
        imagesetpixel($image, random_int(0, 479), random_int(0, 479), (int) imagecolorallocate($image, random_int(90, 200), random_int(90, 200), random_int(90, 200)));
    }
    ob_start();
    imagejpeg($image, null, 82);

    return (string) ob_get_clean();
};

$rows = static fn () => JobApplication::query()->where('applicant_email', 'like', QA_EMAIL_LIKE)->orderBy('id')->get();
$describe = static function (JobApplication $a) use ($disk): array {
    $exists = $a->photo_path !== null && $disk->exists($a->photo_path);

    return [
        'id' => $a->id,
        'application_no' => $a->application_no,
        'applicant' => $a->applicant_name,
        'photo_exists' => $exists,
        'photo_bytes' => $exists ? $disk->size($a->photo_path) : null,
        'photo_sha256' => $exists ? hash('sha256', (string) $disk->get($a->photo_path)) : null,
        'pdf_path' => route('admin.recruitment.applications.pdf', $a, false),
        'print_path' => route('admin.recruitment.applications.print', $a, false),
    ];
};

switch ($mode) {
    case 'setup':
        $posting = JobPosting::query()->where('slug', 'like', QA_SLUG_PREFIX.'%')->first() ?? JobPosting::query()->create([
            'title' => 'QA PDF TEST — স্বেচ্ছাসেবী আহ্বান', 'slug' => QA_SLUG_PREFIX.bin2hex(random_bytes(3)), 'description' => 'QA PDF TEST — disposable, removed by cleanup.',
            'employment_type' => 'volunteer', 'application_mode' => 'rolling', 'accepts_applications' => false, 'status' => 'draft',
        ]);
        $people = [
            'normal' => ['name' => 'নাদিয়া ইসলাম চৌধুরী', 'photo' => $normalJpeg, 'no' => 'QA-PDF-0001'],
            // ~4.2 MB: what the application form accepts (limit 5 MB) and about six times the size that used to break the PDF.
            'large' => ['name' => 'মোহাম্মদ আব্দুল্লাহ আল মামুন', 'photo' => static fn () => $noiseJpeg(2200, 2200, 90), 'no' => 'QA-PDF-0002'],
        ];
        foreach ($people as $kind => $p) {
            if (JobApplication::query()->where('applicant_email', "qa-pdf-test-{$kind}@example.test")->exists()) {
                continue;
            }
            $path = 'applications/photos/qa-pdf-test-'.$kind.'-'.bin2hex(random_bytes(4)).'.jpg';
            $disk->put($path, $p['photo']());
            JobApplication::query()->create([
                'application_no' => $p['no'], 'job_posting_id' => $posting->id, 'applicant_name' => $p['name'], 'applicant_email' => "qa-pdf-test-{$kind}@example.test",
                'applicant_phone' => '+8801711223344', 'district' => 'কুমিল্লা', 'current_location' => 'চান্দিনা', 'profession' => 'শিক্ষার্থী — সমাজবিজ্ঞান',
                'availability' => 'সপ্তাহে ১০ ঘণ্টা', 'preferred_contact' => 'phone', 'skills' => ['fundraising', 'report_writing'], 'other_skills' => 'ক্যালিগ্রাফি, আবৃত্তি',
                'experience' => "কঠিন যুক্তাক্ষর পরীক্ষা: ক্ষমতা, জ্ঞান, বন্ধু, তত্ত্ব, স্বেচ্ছাসেবী, প্রতিষ্ঠান, কার্যক্রম, সাংস্কৃতিক, স্বাক্ষর, দায়িত্ব।\nদ্বিতীয় লাইন: কম্পিউটারে তৈরি, স্বাক্ষর ছাড়াই বৈধ। English line: Provatferi Literary and Cultural Center.",
                'contribution' => 'তহবিল সংগ্রহে সময় দিতে চাই। সাহিত্য ও সংস্কৃতির প্রসারে কাজ করতে চাই। প্রভাতফেরী।',
                'accuracy_declaration' => true, 'privacy_consent' => true, 'contact_consent' => false, 'status' => 'submitted', 'submitted_at' => now(),
                'photo_path' => $path, 'internal_note' => 'QA PDF TEST — disposable',
            ]);
        }
        $out(['mode' => 'setup', 'ok' => true, 'posting_id' => $posting->id, 'applications' => $rows()->map($describe)->all()]);
        break;

    case 'inspect':
        $out(['mode' => 'inspect', 'applications' => $rows()->map($describe)->all(), 'postings' => JobPosting::query()->where('slug', 'like', QA_SLUG_PREFIX.'%')->count()]);
        break;

    case 'sweep':
        $out(['mode' => 'sweep', 'applications' => $rows()->count(), 'postings' => JobPosting::query()->where('slug', 'like', QA_SLUG_PREFIX.'%')->count(),
            'photo_files' => count(array_filter($disk->allFiles('applications/photos'), fn (string $f) => str_contains($f, 'qa-pdf-test-')))]);
        break;

    case 'cleanup':
        if (($argv[2] ?? '') !== 'go') {
            fwrite(STDERR, json_encode(['mode' => 'cleanup', 'ok' => false, 'error' => 'refusing: pass `go`'])."\n");
            exit(2);
        }
        $removed = 0;
        foreach ($rows() as $a) {
            if ($a->photo_path !== null) {
                $disk->delete($a->photo_path);
            }
            $a->delete();
            $removed++;
        }
        foreach ($disk->allFiles('applications/photos') as $f) {
            if (str_contains($f, 'qa-pdf-test-')) {
                $disk->delete($f);
            }
        }
        $posts = JobPosting::query()->withTrashed()->where('slug', 'like', QA_SLUG_PREFIX.'%')->get();
        foreach ($posts as $p) {
            $p->forceDelete();
        }
        $left = ['applications' => JobApplication::query()->where('applicant_email', 'like', QA_EMAIL_LIKE)->count(),
            'postings' => JobPosting::query()->withTrashed()->where('slug', 'like', QA_SLUG_PREFIX.'%')->count(),
            'photo_files' => count(array_filter($disk->allFiles('applications/photos'), fn (string $f) => str_contains($f, 'qa-pdf-test-')))];
        $out(['mode' => 'cleanup', 'ok' => array_sum($left) === 0, 'removed_applications' => $removed, 'removed_postings' => $posts->count(), 'left' => $left]);
        break;

    default:
        fwrite(STDERR, "modes: setup | inspect | sweep | cleanup go\n");
        exit(2);
}
