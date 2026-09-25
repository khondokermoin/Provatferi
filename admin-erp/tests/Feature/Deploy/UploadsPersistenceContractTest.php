<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

/**
 * Deployment-tooling hardening pass, 2026-09-25 (requested after Phase 1
 * recruitment remediation was accepted, before starting the bilingual
 * platform work) — confirms three things about the storage-persistence fix
 * that stops storage/app/private/uploads (every recruitment applicant's
 * photo/CV) from being silently orphaned on an atomic release switch,
 * exactly as happened on 2026-09-24:
 *
 *   1. private uploads survive an atomic release switch (test_a_healthy_copy_...)
 *   2. existing uploaded files remain readable after deployment — proven at
 *      the byte level here (real content compared, not just a count), and
 *      again through the app's own Storage disk in release-manager.php's
 *      smoke-test-isolated action (which needs a real Laravel boot, so it
 *      isn't duplicated in this fast, isolated unit test)
 *   3. no release can silently orphan the uploads directory again — the
 *      regression test below reproduces a REAL partial-copy failure mode
 *      (a path collision copyRecursive can't mkdir through) using the
 *      actual, unmodified copyRecursive() function, and asserts
 *      syncUploadsAndVerify() catches it (ok:false) rather than reporting
 *      success on a partial copy.
 *
 * Requires deploy/remote/lib/uploads-persistence.php directly, in complete
 * isolation from release-manager.php itself (which has top-level CLI
 * dispatch — reads $argv, calls exit() — unsafe to include from a test
 * process). See that library file's own docblock for why the split exists.
 */
class UploadsPersistenceContractTest extends TestCase
{
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('deploy/remote/lib/uploads-persistence.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirRecursive($dir);
        }
        parent::tearDown();
    }

    private function makeTempDir(string $label): string
    {
        $dir = sys_get_temp_dir().'/pf-uploads-contract-'.$label.'-'.bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    public function test_a_healthy_copy_survives_an_atomic_release_switch(): void
    {
        $source = $this->makeTempDir('source-healthy');
        $dest = $this->makeTempDir('dest-healthy').'/uploads'; // does not exist yet — matches a brand new release dir

        mkdir($source.'/applications/photos', 0755, true);
        mkdir($source.'/applications/cv', 0755, true);
        file_put_contents($source.'/applications/photos/real-applicant.jpg', 'genuine-jpeg-bytes-not-a-real-jpeg-but-real-content');
        file_put_contents($source.'/applications/cv/real-applicant.pdf', 'genuine-pdf-bytes');

        $result = syncUploadsAndVerify($source, $dest);

        $this->assertTrue($result['ok'], 'a clean copy with nothing in the way must be reported ok');
        $this->assertTrue($result['source_existed']);
        $this->assertSame(2, $result['source_file_count']);
        $this->assertSame(2, $result['dest_file_count']);

        // Item 2 of the hardening request: existing uploaded files remain
        // READABLE — not just present — after deployment. Compare real bytes,
        // not just that a file of the right name exists.
        $this->assertSame('genuine-jpeg-bytes-not-a-real-jpeg-but-real-content', file_get_contents($dest.'/applications/photos/real-applicant.jpg'));
        $this->assertSame('genuine-pdf-bytes', file_get_contents($dest.'/applications/cv/real-applicant.pdf'));
    }

    public function test_a_brand_new_environment_with_nothing_ever_uploaded_is_not_an_error(): void
    {
        $source = $this->makeTempDir('source-fresh').'/never-existed';
        $dest = $this->makeTempDir('dest-fresh').'/uploads';

        $result = syncUploadsAndVerify($source, $dest);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['source_existed']);
        $this->assertSame(0, $result['source_file_count']);
        $this->assertSame(0, $result['dest_file_count']);
    }

    public function test_a_partial_copy_failure_is_caught_not_silently_reported_ok(): void
    {
        // Reproduces a real failure mode, using the real, unmodified
        // copyRecursive(): a file already sits where copyRecursive needs to
        // mkdir() a subdirectory (e.g. a leftover/colliding path on the
        // release tree), so that one branch's mkdir() — and therefore every
        // file under it — silently fails. This is exactly the class of
        // failure (a permissions problem, a filesystem quirk) the whole
        // hardening pass exists to stop from reporting ok:true.
        $source = $this->makeTempDir('source-regression');
        $dest = $this->makeTempDir('dest-regression').'/uploads';

        mkdir($source.'/applications/photos', 0755, true);
        mkdir($source.'/applications/cv', 0755, true);
        file_put_contents($source.'/applications/photos/one.jpg', 'jpg-bytes');
        file_put_contents($source.'/applications/cv/one.pdf', 'pdf-bytes');

        // Pre-create the destination's "applications" as a FILE, not a
        // directory — copyRecursive's mkdir($target) for applications/
        // (and therefore applications/photos/ and applications/cv/ beneath
        // it) fails silently (mkdir() returns false, emits a warning,
        // throws nothing), so neither file is ever actually copied.
        mkdir($dest, 0755, true);
        file_put_contents($dest.'/applications', 'this occupies the path copyRecursive needs as a directory');

        $result = @syncUploadsAndVerify($source, $dest);

        $this->assertFalse($result['ok'], 'a copy that silently drops files must never be reported ok — this is the exact 2026-09-24 incident, reproduced');
        $this->assertTrue($result['source_existed']);
        $this->assertSame(2, $result['source_file_count']);
        $this->assertLessThan(2, $result['dest_file_count'], 'the collision must have prevented at least one file from landing');
    }
}
